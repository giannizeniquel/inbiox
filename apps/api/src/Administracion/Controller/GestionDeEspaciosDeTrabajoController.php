<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\Repository\EspacioDeTrabajoRepository;
use App\Administracion\UseCase\AsignarAgentesAEspacioDeTrabajoUseCase;
use App\Administracion\UseCase\CreateEspacioDeTrabajoUseCase;
use App\Administracion\UseCase\DesactivarEspacioDeTrabajoUseCase;
use App\Administracion\UseCase\UpdateEspacioDeTrabajoUseCase;
use App\Administracion\EspacioDeTrabajo;
use App\Agente\Agente;
use App\Compartido\ConflictoDeEstadoException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administración de espacios de trabajo.
 *
 * Todos los endpoints requieren ROLE_ADMIN.
 */
#[IsGranted('ROLE_ADMIN')]
final class GestionDeEspaciosDeTrabajoController
{
    public function __construct(
        private readonly EspacioDeTrabajoRepository $espacioDeTrabajoRepository,
        private readonly CreateEspacioDeTrabajoUseCase $createEspacioDeTrabajoUseCase,
        private readonly UpdateEspacioDeTrabajoUseCase $updateEspacioDeTrabajoUseCase,
        private readonly DesactivarEspacioDeTrabajoUseCase $desactivarEspacioDeTrabajoUseCase,
        private readonly AsignarAgentesAEspacioDeTrabajoUseCase $asignarAgentesAEspacioDeTrabajoUseCase,
    ) {}

    #[Route('/api/admin/workspaces', name: 'app_admin_workspaces_list', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($pagina - 1) * $limit;

        $total = $this->espacioDeTrabajoRepository->count([]);
        $espaciosDeTrabajo = $this->espacioDeTrabajoRepository->findBy([], ['id' => 'DESC'], $limit, $offset);

        $items = array_map(fn(EspacioDeTrabajo $w) => $this->serializeEspacioDeTrabajo($w), $espaciosDeTrabajo);

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'page' => $pagina,
                'limit' => $limit,
                'total' => $total,
                'has_next' => ($pagina * $limit) < $total,
            ],
        ]);
    }

    #[Route('/api/admin/workspaces', name: 'app_admin_workspaces_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $espacioDeTrabajo = $this->createEspacioDeTrabajoUseCase->execute(\is_array($data) ? $data : []);
        } catch (ConflictoDeEstadoException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeEspacioDeTrabajo($espacioDeTrabajo), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/workspaces/{id}', name: 'app_admin_workspaces_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $espacioDeTrabajo = $this->espacioDeTrabajoRepository->find($id);

        if (null === $espacioDeTrabajo) {
            return new JsonResponse(['error' => 'Workspace not found'], 404);
        }

        return new JsonResponse($this->serializeEspacioDeTrabajo($espacioDeTrabajo));
    }

    #[Route('/api/admin/workspaces/{id}', name: 'app_admin_workspaces_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $espacioDeTrabajo = $this->espacioDeTrabajoRepository->find($id);

        if (null === $espacioDeTrabajo) {
            return new JsonResponse(['error' => 'Workspace not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->updateEspacioDeTrabajoUseCase->execute($espacioDeTrabajo, \is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeEspacioDeTrabajo($espacioDeTrabajo));
    }

    #[Route('/api/admin/workspaces/{id}', name: 'app_admin_workspaces_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $espacioDeTrabajo = $this->espacioDeTrabajoRepository->find($id);

        if (null === $espacioDeTrabajo) {
            return new JsonResponse(['error' => 'Workspace not found'], 404);
        }

        $this->desactivarEspacioDeTrabajoUseCase->execute($espacioDeTrabajo);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/workspaces/{id}/agents', name: 'app_admin_workspaces_assign_agents', methods: ['PUT'])]
    public function asignarAgentes(int $id, Request $request): JsonResponse
    {
        $espacioDeTrabajo = $this->espacioDeTrabajoRepository->find($id);

        if (null === $espacioDeTrabajo) {
            return new JsonResponse(['error' => 'Workspace not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->asignarAgentesAEspacioDeTrabajoUseCase->execute($espacioDeTrabajo, \is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $idsAsignados = array_map(
            fn(Agente $a) => (int) $a->getId(),
            $espacioDeTrabajo->getAgentes()->toArray()
        );

        return new JsonResponse([
            'workspace_id' => (int) $espacioDeTrabajo->getId(),
            'agent_ids' => array_values($idsAsignados),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEspacioDeTrabajo(EspacioDeTrabajo $espacioDeTrabajo): array
    {
        $agenteIds = [];
        foreach ($espacioDeTrabajo->getAgentes() as $agente) {
            $agenteIds[] = (int) $agente->getId();
        }

        return [
            'id' => (int) $espacioDeTrabajo->getId(),
            'phone_number_id' => $espacioDeTrabajo->getPhoneNumberId(),
            'display_name' => $espacioDeTrabajo->getNombreVisible(),
            'is_active' => $espacioDeTrabajo->isActivo(),
            'agent_count' => count($agenteIds),
            'agent_ids' => $agenteIds,
            'created_at' => $espacioDeTrabajo->getCreadoAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $espacioDeTrabajo->getActualizadoAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
