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
use App\Compartido\StateConflictException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Workspace management.
 *
 * All endpoints require ROLE_ADMIN.
 */
#[IsGranted('ROLE_ADMIN')]
final class GestionDeEspaciosDeTrabajoController
{
    public function __construct(
        private readonly EspacioDeTrabajoRepository $workspaceRepository,
        private readonly CreateEspacioDeTrabajoUseCase $createWorkspaceUseCase,
        private readonly UpdateEspacioDeTrabajoUseCase $updateWorkspaceUseCase,
        private readonly DesactivarEspacioDeTrabajoUseCase $deactivateWorkspaceUseCase,
        private readonly AsignarAgentesAEspacioDeTrabajoUseCase $assignWorkspaceAgentsUseCase,
    ) {}

    #[Route('/api/admin/workspaces', name: 'app_admin_workspaces_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        $total = $this->workspaceRepository->count([]);
        $workspaces = $this->workspaceRepository->findBy([], ['id' => 'DESC'], $limit, $offset);

        $items = array_map(fn(EspacioDeTrabajo $w) => $this->serializeWorkspace($w), $workspaces);

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_next' => ($page * $limit) < $total,
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
            $workspace = $this->createWorkspaceUseCase->execute(\is_array($data) ? $data : []);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeWorkspace($workspace), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/workspaces/{id}', name: 'app_admin_workspaces_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $workspace = $this->workspaceRepository->find($id);

        if (null === $workspace) {
            return new JsonResponse(['error' => 'Workspace not found'], 404);
        }

        return new JsonResponse($this->serializeWorkspace($workspace));
    }

    #[Route('/api/admin/workspaces/{id}', name: 'app_admin_workspaces_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $workspace = $this->workspaceRepository->find($id);

        if (null === $workspace) {
            return new JsonResponse(['error' => 'Workspace not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->updateWorkspaceUseCase->execute($workspace, \is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeWorkspace($workspace));
    }

    #[Route('/api/admin/workspaces/{id}', name: 'app_admin_workspaces_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $workspace = $this->workspaceRepository->find($id);

        if (null === $workspace) {
            return new JsonResponse(['error' => 'Workspace not found'], 404);
        }

        $this->deactivateWorkspaceUseCase->execute($workspace);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/workspaces/{id}/agents', name: 'app_admin_workspaces_assign_agents', methods: ['PUT'])]
    public function assignAgents(int $id, Request $request): JsonResponse
    {
        $workspace = $this->workspaceRepository->find($id);

        if (null === $workspace) {
            return new JsonResponse(['error' => 'Workspace not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->assignWorkspaceAgentsUseCase->execute($workspace, \is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $assignedIds = array_map(
            fn(Agente $a) => (int) $a->getId(),
            $workspace->getAgents()->toArray()
        );

        return new JsonResponse([
            'workspace_id' => (int) $workspace->getId(),
            'agent_ids' => array_values($assignedIds),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWorkspace(EspacioDeTrabajo $workspace): array
    {
        $agentIds = [];
        foreach ($workspace->getAgents() as $agent) {
            $agentIds[] = (int) $agent->getId();
        }

        return [
            'id' => (int) $workspace->getId(),
            'phone_number_id' => $workspace->getPhoneNumberId(),
            'display_name' => $workspace->getDisplayName(),
            'is_active' => $workspace->isActive(),
            'agent_count' => count($agentIds),
            'agent_ids' => $agentIds,
            'created_at' => $workspace->getCreatedAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $workspace->getUpdatedAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
