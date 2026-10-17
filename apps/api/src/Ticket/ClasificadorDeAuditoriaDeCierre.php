<?php

declare(strict_types=1);

namespace App\Ticket;

use App\Administracion\User;

/**
 * Classifies a manual resolve/close action into the owner/override/unowned
 * audit outcome (design.md D2), and builds the namespaced actor reference
 * stored in `ticket_events.source` / `assignments.assigned_by` (design.md D5).
 *
 * Pure, dependency-free — no Doctrine, no HTTP.
 */
final class ClasificadorDeAuditoriaDeCierre
{
    /**
     * @param string   $newStatus       'resolved' | 'closed' (post-transition)
     * @param int|null $previousOwnerId ticket.assigned_agent_id captured under
     *                                  the use case's own `FOR UPDATE` lock (D4)
     * @param int|null $actingAgentId   $user->getAgent()?->getId(), null for an
     *                                  agentless admin/supervisor
     */
    public function clasificar(string $newStatus, ?int $previousOwnerId, ?int $actingAgentId): string
    {
        $isClosed = $newStatus === EstadoDeTicket::CERRADO->value;

        if ($previousOwnerId === null) {
            return $isClosed ? TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE : TipoDeEventoDeTicket::MANUAL_RESUELTO_SIN_RESPONSABLE;
        }

        if ($previousOwnerId === $actingAgentId) {
            return $isClosed ? TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE : TipoDeEventoDeTicket::MANUAL_RESUELTO_RESPONSABLE;
        }

        return $isClosed ? TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO : TipoDeEventoDeTicket::MANUAL_RESUELTO_FORZADO;
    }

    /**
     * Compact, namespaced actor reference for `EventoDeTicket.source` /
     * `Assignment.assignedBy` (design.md D5).
     */
    public function referenciaDeActor(User $user): string
    {
        return \sprintf('u:%d', $user->getId());
    }
}
