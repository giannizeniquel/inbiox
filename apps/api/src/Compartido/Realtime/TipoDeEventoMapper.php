<?php

namespace App\Compartido\Realtime;

use App\Compartido\Outbox\TipoDeEventoDeOutbox;

/**
 * Única tabla que traduce el tipo guardado en el outbox (`TipoDeEventoDeOutbox`) al tipo de
 * evento de Mercure (`TipoDeEventoEnTiempoReal`).
 */
final readonly class TipoDeEventoMapper
{
    /**
     * Mapea un valor guardado de `outbox_events.event_type` al tipo de Mercure.
     *
     * Un ticket actualizado sale como ticket asignado si el payload trae responsable
     * (`assignee_id` u `ownerAgentId`), y como cambio de estado si no.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException si el valor no tiene tipo de Mercure (desconocido, o la
     *                                   bandeja actualizada, que nunca se publica)
     */
    public function aTipoEnTiempoReal(string $valorGuardado, array $payload): TipoDeEventoEnTiempoReal
    {
        return match (TipoDeEventoDeOutbox::tryFrom($valorGuardado)) {
            TipoDeEventoDeOutbox::TICKET_CREADO => TipoDeEventoEnTiempoReal::TICKET_CREADO,
            TipoDeEventoDeOutbox::TICKET_ACTUALIZADO => isset($payload['assignee_id']) || isset($payload['ownerAgentId'])
                ? TipoDeEventoEnTiempoReal::TICKET_ASIGNADO
                : TipoDeEventoEnTiempoReal::ESTADO_DE_TICKET_CAMBIADO,
            TipoDeEventoDeOutbox::MENSAJE_CREADO => TipoDeEventoEnTiempoReal::MENSAJE_AGREGADO,
            TipoDeEventoDeOutbox::MENSAJE_ACTUALIZADO => TipoDeEventoEnTiempoReal::MENSAJE_ACTUALIZADO,
            TipoDeEventoDeOutbox::DISPONIBILIDAD_DE_AGENTE_CAMBIADA => TipoDeEventoEnTiempoReal::DISPONIBILIDAD_DE_AGENTE_CAMBIADA,
            TipoDeEventoDeOutbox::TURNO_DE_AGENTE_CAMBIADO => TipoDeEventoEnTiempoReal::TURNO_DE_AGENTE_CAMBIADO,
            TipoDeEventoDeOutbox::TICKET_LEIDO => TipoDeEventoEnTiempoReal::TICKET_LEIDO,
            TipoDeEventoDeOutbox::BANDEJA_DE_TICKET_ACTUALIZADA, null => throw new \InvalidArgumentException("Unknown event class: $valorGuardado"),
        };
    }
}
