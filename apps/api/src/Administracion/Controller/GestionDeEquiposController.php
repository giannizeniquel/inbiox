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
 * Administración de equipos (Tier 2 #7 — Team/Area Entity, Slice 1).
 *
 * Copia estructural de Admin/GestionDeEspaciosDeTrabajoController.php: misma forma de paginación,
 * mismo registro de errores con reference-id, mismo patrón de asignación masiva
 * dentro de una transacción. Todos los endpoints requieren ROLE_SUPERVISOR o superior (replica el acceso
 * del CRUD de Agent según la spec — dominio de administración, "Team CRUD (Admin/Supervisor)").
 *
 * NOTA: a diferencia de Admin/GestionDeAgentesController.php (que declara `#[IsGranted('ROLE_ADMINISTRADOR')]`
 * en la clase aunque su docblock/access_control apunta a ROLE_SUPERVISOR —
 * una inconsistencia preexistente confirmada por el test de baseline que falla
 * `AgentControllerTest::testListAgentsAllowedForSupervisor`),
 * este controlador declara `#[IsGranted('ROLE_SUPERVISOR')]` directamente para que el
 * atributo a nivel de clase coincida con la entrada de access_control `^/api/administracion/equipos`
 * y realmente otorgue acceso tanto a ROLE_ADMINISTRADOR (vía role_hierarchy) como a
 * ROLE_SUPERVISOR, según la spec.
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

    #[Route('/api/administracion/equipos', name: 'app_admin_teams_list', methods: ['GET'])]
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
                'haySiguiente' => ($pagina * $limit) < $total,
            ],
        ]);
    }

    #[Route('/api/administracion/equipos', name: 'app_admin_teams_create', methods: ['POST'])]
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

    #[Route('/api/administracion/equipos/{id}', name: 'app_admin_teams_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $equipo = $this->equipoRepository->find($id);

        if (null === $equipo) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        return new JsonResponse($this->serializeEquipo($equipo));
    }

    #[Route('/api/administracion/equipos/{id}', name: 'app_admin_teams_update', methods: ['PUT'])]
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

    #[Route('/api/administracion/equipos/{id}', name: 'app_admin_teams_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $equipo = $this->equipoRepository->find($id);

        if (null === $equipo) {
            return new JsonResponse(['error' => 'Team not found'], 404);
        }

        $this->desactivarEquipoUseCase->execute($equipo);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/administracion/equipos/{id}/agentes', name: 'app_admin_teams_assign_agents', methods: ['PUT'])]
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
            'equipoId' => (int) $equipo->getId(),
            'agenteIds' => array_values($idsAsignados),
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
            'espacioDeTrabajoId' => (int) $equipo->getEspacioDeTrabajo()->getId(),
            'nombre' => $equipo->getNombre(),
            'activo' => $equipo->isActivo(),
            'cantidadDeAgentes' => count($agenteIds),
            'agenteIds' => $agenteIds,
            'creadoAt' => $equipo->getCreadoAt()->format('Y-m-d\TH:i:sP'),
            'actualizadoAt' => $equipo->getActualizadoAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
