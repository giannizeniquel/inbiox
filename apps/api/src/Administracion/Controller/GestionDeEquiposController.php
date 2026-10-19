<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\UseCase\AsignarAgentesAEquipoUseCase;
use App\Administracion\UseCase\CreateEquipoUseCase;
use App\Administracion\UseCase\DesactivarEquipoUseCase;
use App\Administracion\UseCase\UpdateEquipoUseCase;
use App\Agente\Agente;
use App\Agente\Repository\EquipoRepository;
use App\Agente\Equipo;
use App\Compartido\ConflictoDeEstadoException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Team management (Tier 2 #7 — Team/Area Entity, Slice 1).
 *
 * Structural copy of Admin/GestionDeEspaciosDeTrabajoController.php: same pagination shape,
 * same reference-id error logging, same transaction-wrapped bulk-assign
 * pattern. All endpoints require ROLE_SUPERVISOR or higher (mirrors Agent
 * CRUD access per spec — administration domain, "Team CRUD (Admin/Supervisor)").
 *
 * NOTE: unlike Admin/GestionDeAgentesController.php (which declares `#[IsGranted('ROLE_ADMIN')]`
 * on the class despite its docblock/access_control intending ROLE_SUPERVISOR —
 * a pre-existing inconsistency confirmed by the failing
 * `AgentControllerTest::testListAgentsAllowedForSupervisor` baseline test),
 * this controller declares `#[IsGranted('ROLE_SUPERVISOR')]` directly so the
 * class-level attribute agrees with the `^/api/admin/teams` access_control
 * entry and actually grants both ROLE_ADMIN (via role_hierarchy) and
 * ROLE_SUPERVISOR, per spec.
 */
#[IsGranted('ROLE_SUPERVISOR')]
final class GestionDeEquiposController
{
    public function __construct(
        private readonly EquipoRepository $equipoRepository,
        private readonly CreateEquipoUseCase $createEquipoUseCase,
        private readonly UpdateEquipoUseCase $updateEquipoUseCase,
        private readonly DesactivarEquipoUseCase $desactivarEquipoUseCase,
        private readonly AsignarAgentesAEquipoUseCase $asignarAgentesAEquipoUseCase,
    ) {}

    #[Route('/api/admin/teams', name: 'app_admin_teams_list', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($pagina - 1) * $limit;

        $total = $this->equipoRepository->count([]);
        $equipos = $this->equipoRepository->findBy([], ['id' => 'DESC'], $limit, $offset);

        $items = array_map(fn(Equipo $t) => $this->serializeEquipo($t), $equipos);

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

    #[Route('/api/admin/teams', name: 'app_admin_teams_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $equipo = $this->createEquipoUseCase->execute(\is_array($data) ? $data : []);
        } catch (ConflictoDeEstadoException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeEquipo($equipo), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $equipo = $this->equipoRepository->find($id);

        if (null === $equipo) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        return new JsonResponse($this->serializeEquipo($equipo));
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $equipo = $this->equipoRepository->find($id);

        if (null === $equipo) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->updateEquipoUseCase->execute($equipo, \is_array($data) ? $data : []);
        } catch (ConflictoDeEstadoException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeEquipo($equipo));
    }

    #[Route('/api/admin/teams/{id}', name: 'app_admin_teams_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $equipo = $this->equipoRepository->find($id);

        if (null === $equipo) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        $this->desactivarEquipoUseCase->execute($equipo);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/teams/{id}/agents', name: 'app_admin_teams_assign_agents', methods: ['PUT'])]
    public function asignarAgentes(int $id, Request $request): JsonResponse
    {
        $equipo = $this->equipoRepository->find($id);

        if (null === $equipo) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->asignarAgentesAEquipoUseCase->execute($equipo, \is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $idsAsignados = array_map(
            fn(Agente $a) => (int) $a->getId(),
            $equipo->getAgentes()->toArray()
        );

        return new JsonResponse([
            'team_id' => (int) $equipo->getId(),
            'agent_ids' => array_values($idsAsignados),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEquipo(Equipo $equipo): array
    {
        $agenteIds = [];
        foreach ($equipo->getAgentes() as $agente) {
            $agenteIds[] = (int) $agente->getId();
        }

        return [
            'id' => (int) $equipo->getId(),
            'workspace_id' => (int) $equipo->getEspacioDeTrabajo()->getId(),
            'name' => $equipo->getNombre(),
            'is_active' => $equipo->isActivo(),
            'agent_count' => count($agenteIds),
            'agent_ids' => $agenteIds,
            'created_at' => $equipo->getCreadoAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $equipo->getActualizadoAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
