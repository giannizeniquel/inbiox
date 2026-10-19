<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\Query\EspacioDeTrabajoDTO;
use App\Administracion\Repository\EspacioDeTrabajoRepository;
use App\Administracion\UseCase\SetEspacioDeTrabajoActivoUseCase;
use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use App\Administracion\EspacioDeTrabajoNoAsignadoException;
use App\Compartido\NotFoundException;
use App\Ticket\Repository\TicketRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The current user's workspaces.
 *
 * GET  /api/me/workspace   — returns the active workspace or 404 if none set
 * PUT  /api/me/workspace   — sets the active workspace
 * GET  /api/me/workspaces  — returns the user's workspaces
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MiEspacioDeTrabajoController
{
    public function __construct(
        private readonly Security $security,
        private readonly EspacioDeTrabajoRepository $espacioDeTrabajoRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly SetEspacioDeTrabajoActivoUseCase $setEspacioDeTrabajoActivoUseCase,
    ) {}

    #[Route('/api/me/workspace', name: 'app_me_workspace', methods: ['GET'])]
    public function getEspacioDeTrabajoActivo(): JsonResponse
    {
        $usuario = $this->security->getUser();
        $agente = $usuario instanceof Usuario ? $usuario->getAgente() : null;
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $espacioDeTrabajoActivo = $agente->getEspacioDeTrabajoActivo();
        if (null === $espacioDeTrabajoActivo) {
            return new JsonResponse(
                ['workspace_id' => null, 'phone_number_id' => null, 'display_name' => null],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(EspacioDeTrabajoActivoDTO::fromEntity($espacioDeTrabajoActivo)->toArray());
    }

    #[Route('/api/me/workspace', name: 'app_me_workspace_set', methods: ['PUT'])]
    public function setEspacioDeTrabajoActivo(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        // Unreachable with the entity user provider; kept as a guard.
        $usuario = $this->security->getUser();
        if (!$usuario instanceof Usuario) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $espacioDeTrabajo = $this->setEspacioDeTrabajoActivoUseCase->execute(
                $usuario,
                $this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_SUPERVISOR'),
                \is_array($data) ? $data : [],
            );
        } catch (NotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (EspacioDeTrabajoNoAsignadoException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(EspacioDeTrabajoActivoDTO::fromEntity($espacioDeTrabajo)->toArray());
    }

    #[Route('/api/me/workspaces', name: 'app_me_workspaces', methods: ['GET'])]
    public function listarMisEspaciosDeTrabajo(): JsonResponse
    {
        $usuario = $this->security->getUser();
        if (!$usuario instanceof Usuario) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Admin/Supervisor: see ALL workspaces
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_SUPERVISOR')) {
            $espaciosDeTrabajo = $this->espacioDeTrabajoRepository->findBy(['activo' => true]);
            $items = array_map(fn(EspacioDeTrabajo $w) => EspacioDeTrabajoDTO::fromEntity($w)->toArray(), $espaciosDeTrabajo);
            return new JsonResponse($items);
        }

        // Agent: only assigned workspaces
        $agente = $usuario->getAgente();
        if (null === $agente) {
            return new JsonResponse([], Response::HTTP_OK);
        }

        $agenteId = (int) $agente->getId();
        $items = [];
        foreach ($agente->getEspaciosDeTrabajo()->toArray() as $w) {
            $hasPendientes = $this->ticketRepository->hasPendientesDeAgente($w->getPhoneNumberId(), $agenteId);
            $items[] = array_merge(EspacioDeTrabajoDTO::fromEntity($w)->toArray(), ['has_pending' => $hasPendientes]);
        }

        return new JsonResponse($items);
    }
}
