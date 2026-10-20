<?php

namespace App\Compartido\Realtime;

/**
 * Maps PHP domain event FQCNs to TipoDeEventoEnTiempoReal enum values.
 * Single source of truth for the FQCN → enum string mapping.
 */
final readonly class TipoDeEventoMapper
{
    private const MAP = [
        \App\Ticket\TicketCreatedEvent::class              => TipoDeEventoEnTiempoReal::TICKET_CREADO,
        \App\Ticket\TicketUpdatedEvent::class              => TipoDeEventoEnTiempoReal::ESTADO_DE_TICKET_CAMBIADO,
        \App\Conversacion\MessageCreatedEvent::class             => TipoDeEventoEnTiempoReal::MENSAJE_AGREGADO,
        \App\Conversacion\MessageUpdatedEvent::class             => TipoDeEventoEnTiempoReal::MENSAJE_ACTUALIZADO,
        \App\Disponibilidad\AgentAvailabilityChangedEvent::class   => TipoDeEventoEnTiempoReal::DISPONIBILIDAD_DE_AGENTE_CAMBIADA,
        \App\Disponibilidad\AgentShiftChangedEvent::class          => TipoDeEventoEnTiempoReal::TURNO_DE_AGENTE_CAMBIADO,
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
    public function isTicketAsignado(string $phpClassName, array $payload): bool
    {
        return $phpClassName === \App\Ticket\TicketUpdatedEvent::class
            && (isset($payload['assignee_id']) || isset($payload['ownerAgentId']));
    }
}
