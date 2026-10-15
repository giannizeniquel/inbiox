<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Disponibilidad\AgentAvailabilityService;
use App\Administracion\Workspace;
use App\Administracion\Repository\WorkspaceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for workspace agent availability.
 *
 * GET /api/workspaces/{id}/available-agents — returns agents available for assignment
 */
#[IsGranted('ROLE_AGENT')]
final class WorkspaceAgentController
{
    public function __construct(
        private readonly AgentAvailabilityService $agentAvailabilityService,
        private readonly WorkspaceRepository $workspaceRepository,
    ) {}

    #[Route('/api/workspaces/{id}/available-agents', name: 'app_workspace_available_agents', methods: ['GET'])]
    public function listAvailableAgents(int $id): JsonResponse
    {
        $workspace = $this->workspaceRepository->find($id);

        if (null === $workspace) {
            return new JsonResponse(['error' => 'Workspace not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$workspace->isActive()) {
            return new JsonResponse(['error' => 'Workspace is not active'], Response::HTTP_BAD_REQUEST);
        }

        $atUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $agents = $this->agentAvailabilityService->listWorkspaceAgentRoster($workspace, $atUtc);

        $data = array_map(fn ($dto) => $dto->toArray(), $agents);

        return new JsonResponse($data);
    }
}
