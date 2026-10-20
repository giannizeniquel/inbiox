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
 * ÚNICO punto de cambio de responsable de los tickets.
 *
 * NINGÚN otro código puede modificar ticket.assigned_agent_id directamente.
 * TODOS los cambios de responsable DEBEN pasar por registrarCambioDeResponsable().
 *
 * Esta es la puerta de auditoría: cada cambio crea una fila de Asignacion,
 * actualiza el ticket y emite eventos de dominio mediante RecolectorDeEventos
 * (que PR4 conecta con Mercure).
 */
final class ResponsabilidadDeTicketService
{
    public function __construct(
        private EntityManagerInterface $em,
        private RecolectorDeEventos $recolectorDeEventos,
    ) {}

    /**
     * Registra un cambio de responsable de un ticket con auditoría completa.
     *
     * @param Ticket  $ticket        El ticket cuyo responsable cambia
     * @param ?Agente  $agente         El nuevo responsable (null para UNASSIGN)
     * @param ?Agente  $agenteAnterior El responsable anterior (null en la asignación inicial)
     * @param TipoDeAsignacion $tipo   El tipo de cambio
     * @param string  $asignadoPor    'system' o el identificador del usuario
     * @param ?string $notas         Notas opcionales
     *
     * @return Asignacion El registro de asignación creado
     */
    public function registrarCambioDeResponsable(
        Ticket $ticket,
        ?Agente $agente,
        ?Agente $agenteAnterior,
        TipoDeAsignacion $tipo,
        string $asignadoPor,
        ?string $notas = null,
    ): Asignacion {
        // 1. Crea la fila de auditoría de Asignacion
        $asignacion = new Asignacion(
            ticket: $ticket,
            agente: $agente,
            asignadoPor: $asignadoPor,
            notas: $notas,
            tipo: $tipo->value,
            agenteAnterior: $agenteAnterior,
        );
        $this->em->persist($asignacion);

        // 2. Actualiza el responsable del ticket (EL único punto de cambio de assigned_agent_id)
        $ticket->setAgenteAsignadoId($agente?->getId());
        $ticket->setActualizadoAt(new \DateTimeImmutable());

        // Si el ticket estaba NEW, pasa a ACTIVE al asignarse
        $estadoCambiado = false;
        if ($ticket->getEstado() === EstadoDeTicket::NUEVO->value) {
            $ticket->setEstado(EstadoDeTicket::ACTIVO->value);
            $estadoCambiado = true;
        }

        // 3. Recolecta los eventos de dominio
        $now = new \DateTimeImmutable();

        $this->recolectorDeEventos->recolectar(new TicketUpdatedEvent(
            eventId: DomainEvent::generateEventId(),
            ticketId: $ticket->getId(),
            occurredAt: $now,
            seq: $ticket->incrementLastEventSeq(),
            version: 1,
            status: $estadoCambiado ? EstadoDeTicket::ACTIVO : null,
            ownerAgentId: $agente?->getId(),
        ));

        // Actualización del inbox del agente anterior (perdió un ticket)
        if ($agenteAnterior !== null) {
            $this->recolectorDeEventos->recolectar(new InboxUpdatedEvent(
                eventId: DomainEvent::generateEventId(),
                ticketId: $ticket->getId(),
                occurredAt: $now,
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
            ));
        }

        // Actualización del inbox del agente nuevo (ganó un ticket)
        if ($agente !== null) {
            $this->recolectorDeEventos->recolectar(new InboxUpdatedEvent(
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
