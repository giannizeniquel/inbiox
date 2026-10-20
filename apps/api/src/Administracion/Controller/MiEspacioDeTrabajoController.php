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
 * Los espacios de trabajo del usuario actual.
 *
 * GET  /api/mi/espacio-de-trabajo    — devuelve el espacio de trabajo activo, o 404 si no hay ninguno definido
 * PUT  /api/mi/espacio-de-trabajo    — define el espacio de trabajo activo
 * GET  /api/mi/espacios-de-trabajo   — devuelve los espacios de trabajo del usuario
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

    #[Route('/api/mi/espacio-de-trabajo', name: 'app_me_workspace', methods: ['GET'])]
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
                ['espacioDeTrabajoId' => null, 'phoneNumberId' => null, 'nombreVisible' => null],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(EspacioDeTrabajoActivoDTO::fromEntity($espacioDeTrabajoActivo)->toArray());
    }

    #[Route('/api/mi/espacio-de-trabajo', name: 'app_me_workspace_set', methods: ['PUT'])]
    public function setEspacioDeTrabajoActivo(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        // Inalcanzable con el user provider de entidad; se conserva como guarda.
        $usuario = $this->security->getUser();
        if (!$usuario instanceof Usuario) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $espacioDeTrabajo = $this->setEspacioDeTrabajoActivoUseCase->execute(
                $usuario,
                $this->security->isGranted('ROLE_ADMINISTRADOR') || $this->security->isGranted('ROLE_SUPERVISOR'),
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

    #[Route('/api/mi/espacios-de-trabajo', name: 'app_me_workspaces', methods: ['GET'])]
    public function listarMisEspaciosDeTrabajo(): JsonResponse
    {
        $usuario = $this->security->getUser();
        if (!$usuario instanceof Usuario) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Admin/Supervisor: ven TODOS los espacios de trabajo
        if ($this->security->isGranted('ROLE_ADMINISTRADOR') || $this->security->isGranted('ROLE_SUPERVISOR')) {
            $espaciosDeTrabajo = $this->espacioDeTrabajoRepository->findBy(['activo' => true]);
            $items = array_map(fn(EspacioDeTrabajo $w) => EspacioDeTrabajoDTO::fromEntity($w)->toArray(), $espaciosDeTrabajo);
            return new JsonResponse($items);
        }

        // Agente: solo los espacios de trabajo asignados
        $agente = $usuario->getAgente();
        if (null === $agente) {
            return new JsonResponse([], Response::HTTP_OK);
        }

        $agenteId = (int) $agente->getId();
        $items = [];
        foreach ($agente->getEspaciosDeTrabajo()->toArray() as $w) {
            $hasPendientes = $this->ticketRepository->hasPendientesDeAgente($w->getPhoneNumberId(), $agenteId);
            $items[] = array_merge(EspacioDeTrabajoDTO::fromEntity($w)->toArray(), ['hayPendientes' => $hasPendientes]);
        }

        return new JsonResponse($items);
    }
}
