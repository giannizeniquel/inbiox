<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Service\AgentAvailabilityService;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for workspace agent availability.
 *
 * GET /api/workspaces/{id}/available-agents — returns agents available for assignment
 */
#[IsGranted('ROLE_AGENT')]
#[Route('/api/workspaces')]
final class WorkspaceAgentController
{
    public function __construct(
        private readonly AgentAvailabilityService $agentAvailabilityService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/{id}/available-agents', methods: ['GET'])]
    public function listAvailableAgents(int $id): JsonResponse
    {
        try {
            $workspace = $this->entityManager->getRepository(Workspace::class)->find($id);

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
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error listing available agents: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }
}
