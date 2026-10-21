<?php

declare(strict_types=1);

namespace App\Compartido\Outbox;

/**
 * Los nombres internos que se guardan en `outbox_events.event_type` (y en `payload.type`).
 *
 * Son un formato persistido y no dependen del nombre de las clases de evento, que se
 * pueden renombrar libremente. No viajan por la red: `TipoDeEventoMapper` los traduce
 * al tipo de Mercure.
 */
enum TipoDeEventoDeOutbox: string
{
    case TICKET_CREADO = 'ticket.creado';
    case TICKET_ACTUALIZADO = 'ticket.actualizado';
    case BANDEJA_DE_TICKET_ACTUALIZADA = 'ticket.bandeja_actualizada';
    case MENSAJE_CREADO = 'mensaje.creado';
    case MENSAJE_ACTUALIZADO = 'mensaje.actualizado';
    case DISPONIBILIDAD_DE_AGENTE_CAMBIADA = 'agente.disponibilidad_cambiada';
    case TURNO_DE_AGENTE_CAMBIADO = 'agente.turno_cambiado';
    case TICKET_LEIDO = 'ticket.leido';
}
