<?php

declare(strict_types=1);

namespace App\Asignacion;

use App\Agente\Agent;
use App\Compartido\DomainEvent;
use App\Compartido\EventCollector;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketUpdatedEvent;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SINGLE point of ownership change for tickets.
 *
 * NO other code may modify ticket.assigned_agent_id directly.
 * ALL ownership changes MUST go through recordOwnershipChange().
 *
 * This is the audit gateway: every change creates an Assignment row,
 * updates the ticket, and emits domain events via EventCollector
 * (which PR4 connects to Mercure).
 */
final class AssignmentService
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventCollector $eventCollector,
    ) {}

    /**
     * Record a ticket ownership change with full audit trail.
     *
     * @param Ticket  $ticket        The ticket whose ownership is changing
     * @param ?Agent  $agent         The new owner (null for UNASSIGN)
     * @param ?Agent  $previousAgent The previous owner (null for initial assignment)
     * @param AssignmentType $type   The type of change
     * @param string  $assignedBy    'system' or user identifier
     * @param ?string $notes         Optional notes
     *
     * @return Assignment The created assignment record
     */
    public function recordOwnershipChange(
        Ticket $ticket,
        ?Agent $agent,
        ?Agent $previousAgent,
        AssignmentType $type,
        string $assignedBy,
        ?string $notes = null,
    ): Assignment {
        // 1. Create Assignment audit row
        $assignment = new Assignment(
            ticket: $ticket,
            agent: $agent,
            assignedBy: $assignedBy,
            notes: $notes,
            type: $type->value,
            previousAgent: $previousAgent,
        );
        $this->em->persist($assignment);

        // 2. Update ticket ownership (THE single point of change for assigned_agent_id)
        $ticket->setAgenteAsignadoId($agent?->getId());
        $ticket->setActualizadoAt(new \DateTimeImmutable());

        // If ticket was NEW, set to ACTIVE on assignment
        $statusChanged = false;
        if ($ticket->getEstado() === EstadoDeTicket::NUEVO->value) {
            $ticket->setEstado(EstadoDeTicket::ACTIVO->value);
            $statusChanged = true;
        }

        // 3. Collect domain events
        $now = new \DateTimeImmutable();

        $this->eventCollector->collect(new TicketUpdatedEvent(
            eventId: DomainEvent::generateEventId(),
            ticketId: $ticket->getId(),
            occurredAt: $now,
            seq: $ticket->incrementLastEventSeq(),
            version: 1,
            status: $statusChanged ? EstadoDeTicket::ACTIVO : null,
            ownerAgentId: $agent?->getId(),
        ));

        // Inbox update for previous agent (lost a ticket)
        if ($previousAgent !== null) {
            $this->eventCollector->collect(new InboxUpdatedEvent(
                eventId: DomainEvent::generateEventId(),
                ticketId: $ticket->getId(),
                occurredAt: $now,
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
            ));
        }

        // Inbox update for new agent (gained a ticket)
        if ($agent !== null) {
            $this->eventCollector->collect(new InboxUpdatedEvent(
                eventId: DomainEvent::generateEventId(),
                ticketId: $ticket->getId(),
                occurredAt: $now,
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
            ));
        }

        return $assignment;
    }
}
