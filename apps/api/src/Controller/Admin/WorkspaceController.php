<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\DTO\WorkspaceDTO;
use App\Domain\Agent\Agent;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Workspace management.
 *
 * All endpoints require ROLE_ADMIN.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/api/admin/workspaces')]
final class WorkspaceController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
            $offset = ($page - 1) * $limit;

            $repo = $this->entityManager->getRepository(Workspace::class);

            $total = (int) $repo->createQueryBuilder('w')
                ->select('COUNT(w.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $workspaces = $repo->createQueryBuilder('w')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->orderBy('w.id', 'DESC')
                ->getQuery()
                ->getResult();

            $items = array_map(fn(Workspace $w) => $this->serializeWorkspace($w), $workspaces);

            return new JsonResponse([
                'data' => $items,
                'meta' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'has_next' => ($page * $limit) < $total,
                ],
            ]);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error listing workspaces: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $phoneNumberId = $data['phoneNumberId'] ?? null;
            $displayName = $data['displayName'] ?? null;

            if (empty($phoneNumberId) || !\is_string($phoneNumberId)) {
                return new JsonResponse(['error' => 'phoneNumberId is required and must be a string'], 400);
            }

            if (empty($displayName) || !\is_string($displayName)) {
                return new JsonResponse(['error' => 'displayName is required and must be a string'], 400);
            }

            // Check for duplicate phone_number_id
            $existing = $this->entityManager->getRepository(Workspace::class)
                ->findOneBy(['phoneNumberId' => $phoneNumberId]);

            if (null !== $existing) {
                return new JsonResponse(['error' => 'A workspace with this phone number ID already exists'], 409);
            }

            $workspace = new Workspace($phoneNumberId, $displayName);

            if (isset($data['isActive'])) {
                $workspace->setActive((bool) $data['isActive']);
            }

            $this->entityManager->persist($workspace);
            $this->entityManager->flush();

            return new JsonResponse($this->serializeWorkspace($workspace), Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error creating workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/{id}', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        try {
            $workspace = $this->entityManager->getRepository(Workspace::class)->find($id);

            if (null === $workspace) {
                return new JsonResponse(['error' => 'Workspace not found'], 404);
            }

            return new JsonResponse($this->serializeWorkspace($workspace));
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $workspace = $this->entityManager->getRepository(Workspace::class)->find($id);

            if (null === $workspace) {
                return new JsonResponse(['error' => 'Workspace not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            if (isset($data['phoneNumberId'])) {
                if (!\is_string($data['phoneNumberId']) || empty($data['phoneNumberId'])) {
                    return new JsonResponse(['error' => 'phoneNumberId must be a non-empty string'], 400);
                }
                $workspace->setPhoneNumberId($data['phoneNumberId']);
            }

            if (isset($data['displayName'])) {
                if (!\is_string($data['displayName']) || empty($data['displayName'])) {
                    return new JsonResponse(['error' => 'displayName must be a non-empty string'], 400);
                }
                $workspace->setDisplayName($data['displayName']);
            }

            if (isset($data['isActive'])) {
                $workspace->setActive((bool) $data['isActive']);
            }

            $this->entityManager->flush();

            return new JsonResponse($this->serializeWorkspace($workspace));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error updating workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $workspace = $this->entityManager->getRepository(Workspace::class)->find($id);

            if (null === $workspace) {
                return new JsonResponse(['error' => 'Workspace not found'], 404);
            }

            // Soft delete: set isActive = false
            $workspace->setActive(false);
            $this->entityManager->flush();

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error deleting workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/{id}/agents', methods: ['PUT'])]
    public function assignAgents(int $id, Request $request): JsonResponse
    {
        try {
            $workspace = $this->entityManager->getRepository(Workspace::class)->find($id);

            if (null === $workspace) {
                return new JsonResponse(['error' => 'Workspace not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $agentIds = $data['agentIds'] ?? [];

            if (!\is_array($agentIds)) {
                return new JsonResponse(['error' => 'agentIds must be an array of integers'], 400);
            }

            // Run in transaction for atomicity
            $this->entityManager->wrapInTransaction(function () use ($workspace, $agentIds): void {
                // Clear existing assignments
                foreach ($workspace->getAgents() as $existingAgent) {
                    $workspace->removeAgent($existingAgent);
                }

                // Add new assignments
                $agentRepo = $this->entityManager->getRepository(Agent::class);
                foreach ($agentIds as $agentId) {
                    if (!\is_int($agentId)) {
                        throw new \InvalidArgumentException('agentIds must contain only integers');
                    }
                    $agent = $agentRepo->find($agentId);
                    if (null !== $agent) {
                        $workspace->addAgent($agent);
                    }
                }
            });

            $assignedIds = array_map(
                fn(Agent $a) => (int) $a->getId(),
                $workspace->getAgents()->toArray()
            );

            return new JsonResponse([
                'workspace_id' => (int) $workspace->getId(),
                'agent_ids' => array_values($assignedIds),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error assigning agents to workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWorkspace(Workspace $workspace): array
    {
        $agentIds = [];
        foreach ($workspace->getAgents() as $agent) {
            $agentIds[] = (int) $agent->getId();
        }

        return [
            'id' => (int) $workspace->getId(),
            'phone_number_id' => $workspace->getPhoneNumberId(),
            'display_name' => $workspace->getDisplayName(),
            'is_active' => $workspace->isActive(),
            'agent_count' => count($agentIds),
            'agent_ids' => $agentIds,
            'created_at' => $workspace->getCreatedAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $workspace->getUpdatedAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
