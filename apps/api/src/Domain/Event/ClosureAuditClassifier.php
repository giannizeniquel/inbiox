<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Ticket\TicketStatus;
use App\Domain\User\User;

/**
 * Classifies a manual resolve/close action into the owner/override/unowned
 * audit outcome (design.md D2), and builds the namespaced actor reference
 * stored in `ticket_events.source` / `assignments.assigned_by` (design.md D5).
 *
 * Pure, dependency-free — no Doctrine, no HTTP.
 */
final class ClosureAuditClassifier
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
        $isClosed = $newStatus === TicketStatus::CLOSED->value;

        if ($previousOwnerId === null) {
            return $isClosed ? TicketEventType::MANUAL_CLOSED_UNOWNED : TicketEventType::MANUAL_RESOLVED_UNOWNED;
        }

        if ($previousOwnerId === $actingAgentId) {
            return $isClosed ? TicketEventType::MANUAL_CLOSED_OWNER : TicketEventType::MANUAL_RESOLVED_OWNER;
        }

        return $isClosed ? TicketEventType::MANUAL_CLOSED_OVERRIDE : TicketEventType::MANUAL_RESOLVED_OVERRIDE;
    }

    /**
     * Compact, namespaced actor reference for `TicketEvent.source` /
     * `Assignment.assignedBy` (design.md D5).
     */
    public function actorReference(User $user): string
    {
        return \sprintf('u:%d', $user->getId());
    }
}
