<?php

declare(strict_types=1);

namespace App\Asignacion;

use App\Agente\Agente;
use App\Compartido\DomainEvent;
use App\Compartido\RecolectorDeEventos;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketUpdatedEvent;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SINGLE point of ownership change for tickets.
 *
 * NO other code may modify ticket.assigned_agent_id directly.
 * ALL ownership changes MUST go through registrarCambioDeResponsable().
 *
 * This is the audit gateway: every change creates an Asignacion row,
 * updates the ticket, and emits domain events via RecolectorDeEventos
 * (which PR4 connects to Mercure).
 */
final class ResponsabilidadDeTicketService
{
    public function __construct(
        private EntityManagerInterface $em,
        private RecolectorDeEventos $eventCollector,
    ) {}

    /**
     * Record a ticket ownership change with full audit trail.
     *
     * @param Ticket  $ticket        The ticket whose ownership is changing
     * @param ?Agente  $agente         The new owner (null for UNASSIGN)
     * @param ?Agente  $agenteAnterior The previous owner (null for initial assignment)
     * @param TipoDeAsignacion $tipo   The type of change
     * @param string  $asignadoPor    'system' or user identifier
     * @param ?string $notas         Optional notes
     *
     * @return Asignacion The created assignment record
     */
    public function registrarCambioDeResponsable(
        Ticket $ticket,
        ?Agente $agente,
        ?Agente $agenteAnterior,
        TipoDeAsignacion $tipo,
        string $asignadoPor,
        ?string $notas = null,
    ): Asignacion {
        // 1. Create Asignacion audit row
        $asignacion = new Asignacion(
            ticket: $ticket,
            agente: $agente,
            asignadoPor: $asignadoPor,
            notas: $notas,
            tipo: $tipo->value,
            agenteAnterior: $agenteAnterior,
        );
        $this->em->persist($asignacion);

        // 2. Update ticket ownership (THE single point of change for assigned_agent_id)
        $ticket->setAgenteAsignadoId($agente?->getId());
        $ticket->setActualizadoAt(new \DateTimeImmutable());

        // If ticket was NEW, set to ACTIVE on assignment
        $estadoCambiado = false;
        if ($ticket->getEstado() === EstadoDeTicket::NUEVO->value) {
            $ticket->setEstado(EstadoDeTicket::ACTIVO->value);
            $estadoCambiado = true;
        }

        // 3. Collect domain events
        $now = new \DateTimeImmutable();

        $this->eventCollector->collect(new TicketUpdatedEvent(
            eventId: DomainEvent::generateEventId(),
            ticketId: $ticket->getId(),
            occurredAt: $now,
            seq: $ticket->incrementLastEventSeq(),
            version: 1,
            status: $estadoCambiado ? EstadoDeTicket::ACTIVO : null,
            ownerAgentId: $agente?->getId(),
        ));

        // Inbox update for previous agent (lost a ticket)
        if ($agenteAnterior !== null) {
            $this->eventCollector->collect(new InboxUpdatedEvent(
                eventId: DomainEvent::generateEventId(),
                ticketId: $ticket->getId(),
                occurredAt: $now,
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
            ));
        }

        // Inbox update for new agent (gained a ticket)
        if ($agente !== null) {
            $this->eventCollector->collect(new InboxUpdatedEvent(
                eventId: DomainEvent::generateEventId(),
                ticketId: $ticket->getId(),
                occurredAt: $now,
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
            ));
        }

        return $asignacion;
    }
}
