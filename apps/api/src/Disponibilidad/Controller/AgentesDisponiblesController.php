<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Administracion\EspacioDeTrabajo;
use App\Administracion\Repository\EspacioDeTrabajoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controlador de la disponibilidad de agentes de un espacio de trabajo.
 *
 * GET /api/workspaces/{id}/available-agents — devuelve los agentes disponibles para asignación
 */
#[IsGranted('ROLE_AGENTE')]
final class AgentesDisponiblesController
{
    public function __construct(
        private readonly DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
        private readonly EspacioDeTrabajoRepository $workspaceRepository,
    ) {}

    #[Route('/api/workspaces/{id}/available-agents', name: 'app_workspace_available_agents', methods: ['GET'])]
    public function listarAgentesDisponibles(int $id): JsonResponse
    {
        $espacioDeTrabajo = $this->workspaceRepository->find($id);

        if (null === $espacioDeTrabajo) {
            return new JsonResponse(['error' => 'Workspace not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$espacioDeTrabajo->isActivo()) {
            return new JsonResponse(['error' => 'Workspace is not active'], Response::HTTP_BAD_REQUEST);
        }

        $atUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $agentes = $this->disponibilidadDeAgenteService->listarAgentesDelEspacioDeTrabajo($espacioDeTrabajo, $atUtc);

        $data = array_map(fn ($dto) => $dto->toArray(), $agentes);

        return new JsonResponse($data);
    }
}
