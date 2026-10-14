<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\UseCase\LinkUserToAgentUseCase;
use App\Administracion\UseCase\UnlinkUserFromAgentUseCase;
use App\Agente\Repository\AgentRepository;
use App\Compartido\NotFoundException;
use App\Compartido\StateConflictException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Agent-User linking.
 *
 * Links and unlinks a User to an Agent.
 * All endpoints require ROLE_ADMIN.
 */
#[IsGranted('ROLE_ADMIN')]
final class AgentLinkController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly LinkUserToAgentUseCase $linkUserToAgentUseCase,
        private readonly UnlinkUserFromAgentUseCase $unlinkUserFromAgentUseCase,
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
    #[Route('/api/admin/agents/{id}/link-user', name: 'admin_agents_link_user', methods: ['POST'])]
    public function linkUser(int $id, Request $request): JsonResponse
    {
        $agent = $this->agentRepository->find($id);

        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $user = $this->linkUserToAgentUseCase->execute($agent, \is_array($data) ? $data : []);
        } catch (NotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'id' => (int) $agent->getId(),
            'user_id' => (int) $user->getId(),
            'user_email' => $user->getEmail(),
        ]);
    }

    /**
     * DELETE /api/admin/agents/{id}/link-user
     *
     * Unlinks the User from an Agent.
     */
    #[Route('/api/admin/agents/{id}/link-user', name: 'admin_agents_unlink_user', methods: ['DELETE'])]
    public function unlinkUser(int $id): JsonResponse
    {
        $agent = $this->agentRepository->find($id);

        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        try {
            $this->unlinkUserFromAgentUseCase->execute($agent);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
