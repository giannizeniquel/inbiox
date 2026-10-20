<?php

declare(strict_types=1);

namespace App\Compartido\Security;

use App\Administracion\Usuario;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Determina el alcance de responsabilidad del usuario autenticado actual.
 *
 * INTENCIONALMENTE NO HACE NADA HOY — no es un bug:
 *
 * `getAgenteId()` siempre devuelve null, para todos los roles, incluido AGENT.
 * Es una decisión de producto confirmada (ver docs/04_sprints/sprint_16
 * (Production Readiness).md): los reportes/métricas NO se restringen por
 * agente — todo agente autenticado puede ver las métricas de los demás agentes,
 * por ahora. Los datos de tickets/conversaciones siguen acotados por espacio de trabajo (ver
 * AlcanceDeEspacioDeTrabajo), pero no por el agente responsable individual.
 *
 * Esta clase (y el armado de la cláusula WHERE por `agent_id` que la consume
 * en ReporteRepository/ReporteService) se mantiene para que
 * la restricción por agente pueda reintroducirse más adelante sin rehacer
 * cada query — no "arreglar" `getAgenteId()` para que devuelva el ID del agente
 * actual sin una decisión de producto explícita en ese sentido.
 */
final class AlcanceDeResponsabilidad
{
    public function __construct(
        private readonly Security $security,
    ) {}

    /**
     * Obtiene el ID de agente que acota al usuario actual.
     *
     * - ROLE_ADMINISTRADOR / ROLE_SUPERVISOR: null (sin alcance — ven todo)
     * - ROLE_AGENTE: null (los agentes ven todos los tickets de su espacio de trabajo;
     *   la segregación la resuelve AlcanceDeEspacioDeTrabajo.phone_number_id)
     * - Sin usuario autenticado: null
     */
    public function getAgenteId(): ?int
    {
        $usuario = $this->security->getUser();

        if (!$usuario instanceof Usuario) {
            return null;
        }

        // Modelo basado en espacios de trabajo: todos los usuarios autenticados ven todos los tickets
        // de su espacio de trabajo activo. La segregación por espacio de trabajo se resuelve
        // a nivel de query mediante AlcanceDeEspacioDeTrabajo (filtro por phone_number_id).
        // La responsabilidad por agente ya no se aplica a nivel de query.
        return null;
    }
}
