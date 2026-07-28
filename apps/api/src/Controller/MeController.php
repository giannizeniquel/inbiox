<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\DTO\ActiveWorkspaceDTO;
use App\Application\DTO\WorkspaceDTO;
use App\Domain\Agent\Agent;
use App\Domain\User\User;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for current user's workspace preference.
 *
 * GET  /api/me/workspace   — returns the active workspace or 404 if none set
 * PUT  /api/me/workspace   — sets the active workspace
 * GET  /api/me/workspaces  — returns the agent's assigned workspaces
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/api/me')]
final class MeController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/workspace', methods: ['GET'])]
    public function getActiveWorkspace(): JsonResponse
    {
        try {
            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            $activeWorkspace = $agent->getActiveWorkspace();
            if (null === $activeWorkspace) {
                return new JsonResponse(
                    ['workspace_id' => null, 'phone_number_id' => null, 'display_name' => null],
                    Response::HTTP_NOT_FOUND,
                );
            }

            return new JsonResponse(ActiveWorkspaceDTO::fromEntity($activeWorkspace)->toArray());
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching active workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/workspace', methods: ['PUT'])]
    public function setActiveWorkspace(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
            }

            $workspaceId = $data['workspaceId'] ?? null;

            if (null === $workspaceId || !\is_int($workspaceId)) {
                return new JsonResponse(['error' => 'workspaceId is required and must be an integer'], Response::HTTP_BAD_REQUEST);
            }

            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            $workspace = $this->entityManager->getRepository(Workspace::class)->find($workspaceId);

            if (null === $workspace) {
                return new JsonResponse(['error' => 'Workspace not found'], Response::HTTP_NOT_FOUND);
            }

            if (!$workspace->isActive()) {
                return new JsonResponse(['error' => 'Workspace is not active'], Response::HTTP_BAD_REQUEST);
            }

            // Verify the agent is assigned to this workspace
            if (!$agent->getWorkspaces()->contains($workspace)) {
                return new JsonResponse(['error' => 'Agent is not assigned to this workspace'], Response::HTTP_FORBIDDEN);
            }

            $agent->setActiveWorkspace($workspace);
            $this->entityManager->flush();

            return new JsonResponse(ActiveWorkspaceDTO::fromEntity($workspace)->toArray());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error setting active workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/workspaces', methods: ['GET'])]
    public function listMyWorkspaces(): JsonResponse
    {
        try {
            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            $workspaces = $agent->getWorkspaces()->toArray();
            $items = array_map(fn(Workspace $w) => WorkspaceDTO::fromEntity($w)->toArray(), $workspaces);

            return new JsonResponse($items);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error listing agent workspaces: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    private function getAgent(): ?Agent
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return $user->getAgent();
    }
}
