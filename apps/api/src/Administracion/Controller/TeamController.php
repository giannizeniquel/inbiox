<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\UseCase\AssignTeamAgentsUseCase;
use App\Administracion\UseCase\CreateTeamUseCase;
use App\Administracion\UseCase\DeactivateTeamUseCase;
use App\Administracion\UseCase\UpdateTeamUseCase;
use App\Agente\Agente;
use App\Agente\Repository\EquipoRepository;
use App\Agente\Equipo;
use App\Compartido\StateConflictException;
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
        private readonly EquipoRepository $teamRepository,
        private readonly CreateTeamUseCase $createTeamUseCase,
        private readonly UpdateTeamUseCase $updateTeamUseCase,
        private readonly DeactivateTeamUseCase $deactivateTeamUseCase,
        private readonly AssignTeamAgentsUseCase $assignTeamAgentsUseCase,
    ) {}

    #[Route('/api/admin/teams', name: 'app_admin_teams_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        $total = $this->teamRepository->count([]);
        $teams = $this->teamRepository->findBy([], ['id' => 'DESC'], $limit, $offset);

        $items = array_map(fn(Equipo $t) => $this->serializeTeam($t), $teams);

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
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $team = $this->createTeamUseCase->execute(\is_array($data) ? $data : []);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeTeam($team), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $team = $this->teamRepository->find($id);

        if (null === $team) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        return new JsonResponse($this->serializeTeam($team));
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $team = $this->teamRepository->find($id);

        if (null === $team) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->updateTeamUseCase->execute($team, \is_array($data) ? $data : []);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeTeam($team));
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $team = $this->teamRepository->find($id);

        if (null === $team) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        $this->deactivateTeamUseCase->execute($team);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/teams/{id}/agents', name: 'app_admin_teams_assign_agents', methods: ['PUT'])]
    public function assignAgents(int $id, Request $request): JsonResponse
    {
        $team = $this->teamRepository->find($id);

        if (null === $team) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->assignTeamAgentsUseCase->execute($team, \is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $assignedIds = array_map(
            fn(Agente $a) => (int) $a->getId(),
            $team->getAgents()->toArray()
        );

        return new JsonResponse([
            'team_id' => (int) $team->getId(),
            'agent_ids' => array_values($assignedIds),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTeam(Equipo $team): array
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
