<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Agent\Agent;
use App\Domain\User\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Agent-User linking.
 *
 * Links and unlinks a User to an Agent.
 * All endpoints require ROLE_ADMIN.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/api/admin/agents')]
final class AgentLinkController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * POST /api/admin/agents/{id}/link-user
     *
     * Links a User to an Agent.
     * - 400 if agent already has a user linked
     * - 400 if user already linked to another agent
     * - 400 if user is deactivated (Q3 from design)
     * - 409 on UNIQUE constraint violation
     */
    #[Route('/{id}/link-user', methods: ['POST'])]
    public function linkUser(int $id, Request $request): JsonResponse
    {
        try {
            $agent = $this->entityManager->getRepository(Agent::class)->find($id);

            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $userId = $data['user_id'] ?? null;

            if (null === $userId || !\is_int($userId)) {
                return new JsonResponse(['error' => 'user_id is required and must be an integer'], 400);
            }

            $user = $this->entityManager->getRepository(User::class)->find($userId);

            if (null === $user) {
                return new JsonResponse(['error' => 'User not found'], 404);
            }

            // Q3: Deny linking deactivated user to agent
            if (!$user->isActive()) {
                return new JsonResponse(['error' => 'Cannot link a deactivated user to an agent'], 400);
            }

            // Check if agent already has a user
            if (null !== $agent->getUser()) {
                return new JsonResponse(['error' => 'Agent already linked to a user'], 400);
            }

            // Check if user already has an agent (UNIQUE constraint)
            if (null !== $user->getAgent()) {
                return new JsonResponse(['error' => 'User already linked to an agent'], 400);
            }

            $agent->setUser($user);
            $user->setAgent($agent);

            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException $e) {
                // 409 on UNIQUE conflict (defensive - should not happen with checks above)
                return new JsonResponse(['error' => 'Link conflict: agent or user already linked'], 409);
            }

            return new JsonResponse([
                'id' => (int) $agent->getId(),
                'user_id' => (int) $user->getId(),
                'user_email' => $user->getEmail(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error linking user to agent: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * DELETE /api/admin/agents/{id}/link-user
     *
     * Unlinks the User from an Agent.
     */
    #[Route('/{id}/link-user', methods: ['DELETE'])]
    public function unlinkUser(int $id): JsonResponse
    {
        try {
            $agent = $this->entityManager->getRepository(Agent::class)->find($id);

            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], 404);
            }

            if (null === $agent->getUser()) {
                return new JsonResponse(['error' => 'Agent has no linked user'], 400);
            }

            $user = $agent->getUser();
            $agent->setUser(null);
            if (null !== $user) {
                $user->setAgent(null);
            }
            $this->entityManager->flush();

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error unlinking user from agent: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }
}
