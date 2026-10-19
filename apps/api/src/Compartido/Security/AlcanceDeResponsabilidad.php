<?php

declare(strict_types=1);

namespace App\Compartido\Security;

use App\Administracion\Usuario;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Determines the ownership scope for the current authenticated user.
 *
 * INTENTIONALLY A NO-OP TODAY — not a bug:
 *
 * `getAgentId()` always returns null, for every role including AGENT.
 * This is a confirmed product decision (see docs/04_sprints/sprint_16
 * (Production Readiness).md): reports/metrics are NOT restricted by
 * agent — every authenticated agent can see every other agent's metrics,
 * for now. Ticket/conversation data is still scoped by workspace (see
 * AlcanceDeEspacioDeTrabajo), just not by individual agent ownership.
 *
 * This class (and the `agent_id` WHERE-clause plumbing that consumes it
 * in ReporteRepository/ReporteService) is kept in place so that
 * per-agent restriction can be reintroduced later without re-plumbing
 * every query — do not "fix" `getAgentId()` to return the current
 * agent's ID without an explicit product decision to do so.
 */
final class AlcanceDeResponsabilidad
{
    public function __construct(
        private readonly Security $security,
    ) {}

    /**
     * Get the scoped agent ID for the current user.
     *
     * - ROLE_ADMIN / ROLE_SUPERVISOR: null (no scope — see everything)
     * - ROLE_AGENT: null (agents see all tickets in their workspace;
     *   segregation is handled by AlcanceDeEspacioDeTrabajo.phone_number_id)
     * - No authenticated user: null
     */
    public function getAgentId(): ?int
    {
        $user = $this->security->getUser();

        if (!$user instanceof Usuario) {
            return null;
        }

        // Workspace-based model: all authenticated users see all tickets
        // in their active workspace. Segregation by workspace is handled
        // at the query level via AlcanceDeEspacioDeTrabajo (phone_number_id filter).
        // Agent-level ownership is no longer enforced at the query level.
        return null;
    }
}
