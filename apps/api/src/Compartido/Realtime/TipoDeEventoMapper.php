<?php

namespace App\Compartido\Realtime;

/**
 * Mapea los FQCN de los eventos de dominio de PHP a valores del enum TipoDeEventoEnTiempoReal.
 * Única fuente de verdad del mapeo de FQCN → string del enum.
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
     * Mapea un FQCN de PHP a su valor string correspondiente de TipoDeEventoEnTiempoReal.
     *
     * @throws \InvalidArgumentException si no existe un mapeo para el nombre de la clase
     */
    public function toEnum(string $phpClassName): string
    {
        if (!isset(self::MAP[$phpClassName])) {
            throw new \InvalidArgumentException("Unknown event class: $phpClassName");
        }
        return self::MAP[$phpClassName]->value;
    }

    /**
     * Determina si un TicketUpdatedEvent representa un evento ticket.assigned
     * (tiene assignee_id en el payload).
     */
    public function isTicketAsignado(string $phpClassName, array $payload): bool
    {
        return $phpClassName === \App\Ticket\TicketUpdatedEvent::class
            && (isset($payload['assignee_id']) || isset($payload['ownerAgentId']));
    }
}
