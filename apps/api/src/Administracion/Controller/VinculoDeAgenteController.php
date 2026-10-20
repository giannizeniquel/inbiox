<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\UseCase\VincularUsuarioConAgenteUseCase;
use App\Administracion\UseCase\DesvincularUsuarioDeAgenteUseCase;
use App\Agente\Repository\AgenteRepository;
use App\Compartido\NotFoundException;
use App\Compartido\ConflictoDeEstadoException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administración del vínculo Agente-Usuario.
 *
 * Vincula y desvincula un Usuario de un Agente.
 * Todos los endpoints requieren ROLE_ADMINISTRADOR.
 */
#[IsGranted('ROLE_ADMINISTRADOR')]
final class VinculoDeAgenteController
{
    public function __construct(
        private readonly AgenteRepository $agenteRepository,
        private readonly VincularUsuarioConAgenteUseCase $vincularUsuarioConAgenteUseCase,
        private readonly DesvincularUsuarioDeAgenteUseCase $desvincularUsuarioDeAgenteUseCase,
    ) {}

    /**
     * POST /api/administracion/agentes/{id}/vincular-usuario
     *
     * Vincula un Usuario con un Agente.
     * - 400 si el agente ya tiene un usuario vinculado
     * - 400 si el usuario ya está vinculado a otro agente
     * - 400 si el usuario está desactivado (Q3 del diseño)
     * - 409 ante una violación de la restricción UNIQUE
     */
    #[Route('/api/administracion/agentes/{id}/vincular-usuario', name: 'admin_agents_link_user', methods: ['POST'])]
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
        } catch (ConflictoDeEstadoException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'id' => (int) $agente->getId(),
            'usuarioId' => (int) $usuario->getId(),
            'usuarioEmail' => $usuario->getEmail(),
        ]);
    }

    /**
     * DELETE /api/administracion/agentes/{id}/vincular-usuario
     *
     * Desvincula el Usuario de un Agente.
     */
    #[Route('/api/administracion/agentes/{id}/vincular-usuario', name: 'admin_agents_unlink_user', methods: ['DELETE'])]
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
