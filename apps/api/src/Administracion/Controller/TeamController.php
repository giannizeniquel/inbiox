<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Agente\Agent;
use App\Agente\Team;
use App\Administracion\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Team management (Tier 2 #7 — Team/Area Entity, Slice 1).
 *
 * Structural copy of Admin/WorkspaceController.php: same pagination shape,
 * same reference-id error logging, same transaction-wrapped bulk-assign
 * pattern. All endpoints require ROLE_SUPERVISOR or higher (mirrors Agent
 * CRUD access per spec — administration domain, "Team CRUD (Admin/Supervisor)").
 *
 * NOTE: unlike Admin/AgentController.php (which declares `#[IsGranted('ROLE_ADMIN')]`
 * on the class despite its docblock/access_control intending ROLE_SUPERVISOR —
 * a pre-existing inconsistency confirmed by the failing
 * `AgentControllerTest::testListAgentsAllowedForSupervisor` baseline test),
 * this controller declares `#[IsGranted('ROLE_SUPERVISOR')]` directly so the
 * class-level attribute agrees with the `^/api/admin/teams` access_control
 * entry and actually grants both ROLE_ADMIN (via role_hierarchy) and
 * ROLE_SUPERVISOR, per spec.
 */
#[IsGranted('ROLE_SUPERVISOR')]
final class TeamController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/api/admin/teams', name: 'app_admin_teams_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        $repo = $this->entityManager->getRepository(Team::class);

        $total = (int) $repo->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $teams = $repo->createQueryBuilder('t')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        $items = array_map(fn(Team $t) => $this->serializeTeam($t), $teams);

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_next' => ($page * $limit) < $total,
            ],
        ]);
    }

    #[Route('/api/admin/teams', name: 'app_admin_teams_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $workspaceId = $data['workspaceId'] ?? null;
            $name = $data['name'] ?? null;

            if (empty($workspaceId) || !\is_int($workspaceId)) {
                return new JsonResponse(['error' => 'workspaceId is required and must be an integer'], 400);
            }

            if (empty($name) || !\is_string($name)) {
                return new JsonResponse(['error' => 'name is required and must be a string'], 400);
            }

            $workspace = $this->entityManager->getRepository(Workspace::class)->find($workspaceId);

            if (null === $workspace) {
                return new JsonResponse(['error' => 'Workspace not found'], 400);
            }

            // Check for duplicate (workspace_id, name)
            $existing = $this->entityManager->getRepository(Team::class)
                ->findOneBy(['workspace' => $workspace, 'name' => $name]);

            if (null !== $existing) {
                return new JsonResponse(['error' => 'A team with this name already exists in this workspace'], 409);
            }

            $team = new Team($workspace, $name);

            if (isset($data['isActive'])) {
                $team->setActive((bool) $data['isActive']);
            }

            $this->entityManager->persist($team);
            $this->entityManager->flush();

            return new JsonResponse($this->serializeTeam($team), Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);

        if (null === $team) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        return new JsonResponse($this->serializeTeam($team));
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $team = $this->entityManager->getRepository(Team::class)->find($id);

            if (null === $team) {
                return new JsonResponse(['error' => 'Team not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            if (isset($data['name'])) {
                if (!\is_string($data['name']) || empty($data['name'])) {
                    return new JsonResponse(['error' => 'name must be a non-empty string'], 400);
                }

                $duplicate = $this->entityManager->getRepository(Team::class)
                    ->findOneBy(['workspace' => $team->getWorkspace(), 'name' => $data['name']]);
                if (null !== $duplicate && $duplicate->getId() !== $team->getId()) {
                    return new JsonResponse(['error' => 'A team with this name already exists in this workspace'], 409);
                }

                $team->setName($data['name']);
            }

            if (isset($data['isActive'])) {
                $team->setActive((bool) $data['isActive']);
            }

            $this->entityManager->flush();

            return new JsonResponse($this->serializeTeam($team));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);

        if (null === $team) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        // Soft delete: set isActive = false. MUST NOT clear tickets.team_id
        // on referencing tickets (spec: Team Deactivation Retains Historical
        // Ticket Attribution) — no cascading update is performed here.
        $team->setActive(false);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/teams/{id}/agents', name: 'app_admin_teams_assign_agents', methods: ['PUT'])]
    public function assignAgents(int $id, Request $request): JsonResponse
    {
        try {
            $team = $this->entityManager->getRepository(Team::class)->find($id);

            if (null === $team) {
                return new JsonResponse(['error' => 'Team not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $agentIds = $data['agentIds'] ?? [];

            if (!\is_array($agentIds)) {
                return new JsonResponse(['error' => 'agentIds must be an array of integers'], 400);
            }

            // Run in transaction for atomicity — Team::addAgent() throws
            // \InvalidArgumentException (workspace-membership invariant,
            // design.md D3) which rolls back the whole batch (hard reject,
            // all-or-nothing), mirroring WorkspaceController::assignAgents.
            $this->entityManager->wrapInTransaction(function () use ($team, $agentIds): void {
                foreach ($team->getAgents() as $existingAgent) {
                    $team->removeAgent($existingAgent);
                }

                $agentRepo = $this->entityManager->getRepository(Agent::class);
                foreach ($agentIds as $agentId) {
                    if (!\is_int($agentId)) {
                        throw new \InvalidArgumentException('agentIds must contain only integers');
                    }
                    $agent = $agentRepo->find($agentId);
                    if (null !== $agent) {
                        $team->addAgent($agent);
                    }
                }
            });

            $assignedIds = array_map(
                fn(Agent $a) => (int) $a->getId(),
                $team->getAgents()->toArray()
            );

            return new JsonResponse([
                'team_id' => (int) $team->getId(),
                'agent_ids' => array_values($assignedIds),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTeam(Team $team): array
    {
        $agentIds = [];
        foreach ($team->getAgents() as $agent) {
            $agentIds[] = (int) $agent->getId();
        }

        return [
            'id' => (int) $team->getId(),
            'workspace_id' => (int) $team->getWorkspace()->getId(),
            'name' => $team->getName(),
            'is_active' => $team->isActive(),
            'agent_count' => count($agentIds),
            'agent_ids' => $agentIds,
            'created_at' => $team->getCreatedAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $team->getUpdatedAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
