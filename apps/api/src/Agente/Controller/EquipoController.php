<?php

declare(strict_types=1);

namespace App\Agente\Controller;

use App\Agente\Equipo;
use App\Administracion\Usuario;
use App\Agente\Repository\EquipoRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Listado de equipos no exclusivo de admin y acotado al espacio de trabajo (Tier 2 #7 — Team/Area Entity,
 * Slice 1). Se construye ahora como parte de la superficie de API del Slice 1 para el futuro
 * desplegable de filtro de la bandeja de entrada (Slice 3).
 *
 * Accesible para CUALQUIER agente autenticado (incluido ROLE_AGENT), no
 * solo para admin, a diferencia de Controller/Admin/TeamController. Los resultados se acotan
 * al espacio o los espacios de trabajo del solicitante mediante su entidad Agente vinculada.
 *
 * Los equipos desactivados SÍ se incluyen (a diferencia de un selector de asignación activa):
 * según el spec (team-management, "Team Deactivation Retains Historical Ticket
 * Attribution"), un equipo desactivado debe seguir siendo seleccionable en los filtros
 * de bandeja de entrada y de reportes aunque esté oculto en los selectores de NUEVA asignación.
 */
#[IsGranted('ROLE_AGENT')]
final class EquipoController
{
    public function __construct(
        private readonly EquipoRepository $equipoRepository,
        private readonly Security $security,
    ) {}

    #[Route('/api/teams', name: 'app_teams_list', methods: ['GET'])]
    public function listar(): JsonResponse
    {
        $usuario = $this->security->getUser();
        if (!$usuario instanceof Usuario) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $agente = $usuario->getAgente();
        if (null === $agente) {
            return new JsonResponse([]);
        }

        $espaciosDeTrabajo = $agente->getEspaciosDeTrabajo()->toArray();
        if ([] === $espaciosDeTrabajo) {
            return new JsonResponse([]);
        }

        $equipos = $this->equipoRepository->findByEspaciosDeTrabajo($espaciosDeTrabajo);

        $items = array_map(fn(Equipo $t) => [
            'id' => (int) $t->getId(),
            'workspace_id' => (int) $t->getEspacioDeTrabajo()->getId(),
            'name' => $t->getNombre(),
            'is_active' => $t->isActivo(),
        ], $equipos);

        return new JsonResponse($items);
    }
}
