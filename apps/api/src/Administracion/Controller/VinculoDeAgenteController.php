<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\UseCase\VincularUsuarioConAgenteUseCase;
use App\Administracion\UseCase\DesvincularUsuarioDeAgenteUseCase;
use App\Agente\Repository\AgenteRepository;
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
final class VinculoDeAgenteController
{
    public function __construct(
        private readonly AgenteRepository $agenteRepository,
        private readonly VincularUsuarioConAgenteUseCase $vincularUsuarioConAgenteUseCase,
        private readonly DesvincularUsuarioDeAgenteUseCase $desvincularUsuarioDeAgenteUseCase,
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
    public function vincularUsuario(int $id, Request $request): JsonResponse
    {
        $agente = $this->agenteRepository->find($id);

        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $usuario = $this->vincularUsuarioConAgenteUseCase->execute($agente, \is_array($data) ? $data : []);
        } catch (NotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'id' => (int) $agente->getId(),
            'user_id' => (int) $usuario->getId(),
            'user_email' => $usuario->getEmail(),
        ]);
    }

    /**
     * DELETE /api/admin/agents/{id}/link-user
     *
     * Unlinks the User from an Agent.
     */
    #[Route('/api/admin/agents/{id}/link-user', name: 'admin_agents_unlink_user', methods: ['DELETE'])]
    public function desvincularUsuario(int $id): JsonResponse
    {
        $agente = $this->agenteRepository->find($id);

        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        try {
            $this->desvincularUsuarioDeAgenteUseCase->execute($agente);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
