<?php

namespace App\Compartido\Realtime;

/**
 * Maps PHP domain event FQCNs to TipoDeEventoEnTiempoReal enum values.
 * Single source of truth for the FQCN → enum string mapping.
 */
final readonly class TipoDeEventoMapper
{
    private const MAP = [
        \App\Ticket\TicketCreatedEvent::class              => TipoDeEventoEnTiempoReal::TicketCreated,
        \App\Ticket\TicketUpdatedEvent::class              => TipoDeEventoEnTiempoReal::TicketStatusChanged,
        \App\Conversacion\MessageCreatedEvent::class             => TipoDeEventoEnTiempoReal::MessageAdded,
        \App\Conversacion\MessageUpdatedEvent::class             => TipoDeEventoEnTiempoReal::MessageUpdated,
        \App\Disponibilidad\AgentAvailabilityChangedEvent::class   => TipoDeEventoEnTiempoReal::AGENT_AVAILABILITY_CHANGED,
        \App\Disponibilidad\AgentShiftChangedEvent::class          => TipoDeEventoEnTiempoReal::AGENT_SHIFT_CHANGED,
    ];

    /**
     * Map a PHP FQCN to its corresponding TipoDeEventoEnTiempoReal string value.
     *
     * @throws \InvalidArgumentException if no mapping exists for the class name
     */
    public function toEnum(string $phpClassName): string
    {
        if (!isset(self::MAP[$phpClassName])) {
            throw new \InvalidArgumentException("Unknown event class: $phpClassName");
        }
        return self::MAP[$phpClassName]->value;
    }

    /**
     * Determine if a TicketUpdatedEvent represents a ticket.assigned event
     * (has assignee_id in the payload).
     */
    public function isTicketAssigned(string $phpClassName, array $payload): bool
    {
        return $phpClassName === \App\Ticket\TicketUpdatedEvent::class
            && (isset($payload['assignee_id']) || isset($payload['ownerAgentId']));
    }
}
