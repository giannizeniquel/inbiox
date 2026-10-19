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
 * Non-admin, workspace-scoped Team listing (Tier 2 #7 — Team/Area Entity,
 * Slice 1). Built now as part of Slice 1's API surface for the future
 * inbox filter dropdown (Slice 3).
 *
 * Accessible to ANY authenticated agent (ROLE_AGENT included) — not
 * admin-only, unlike Controller/Admin/TeamController. Results are scoped
 * to the requester's own workspace(s) via their linked Agent entity.
 *
 * Deactivated teams ARE included (unlike an active-assignment picker):
 * per spec (team-management, "Team Deactivation Retains Historical Ticket
 * Attribution"), a deactivated team must remain selectable in inbox/report
 * filters even though it's hidden from NEW-assignment pickers.
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
