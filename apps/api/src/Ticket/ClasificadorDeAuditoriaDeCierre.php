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
    public function classify(string $newStatus, ?int $previousOwnerId, ?int $actingAgentId): string
    {
        $isClosed = $newStatus === EstadoDeTicket::CLOSED->value;

        if ($previousOwnerId === null) {
            return $isClosed ? TipoDeEventoDeTicket::MANUAL_CLOSED_UNOWNED : TipoDeEventoDeTicket::MANUAL_RESOLVED_UNOWNED;
        }

        if ($previousOwnerId === $actingAgentId) {
            return $isClosed ? TipoDeEventoDeTicket::MANUAL_CLOSED_OWNER : TipoDeEventoDeTicket::MANUAL_RESOLVED_OWNER;
        }

        return $isClosed ? TipoDeEventoDeTicket::MANUAL_CLOSED_OVERRIDE : TipoDeEventoDeTicket::MANUAL_RESOLVED_OVERRIDE;
    }

    /**
     * Compact, namespaced actor reference for `EventoDeTicket.source` /
     * `Assignment.assignedBy` (design.md D5).
     */
    public function actorReference(User $user): string
    {
        return \sprintf('u:%d', $user->getId());
    }
}
