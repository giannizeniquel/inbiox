<?php

namespace App\Compartido\Realtime;

/**
 * Backed enum of realtime event types for the RealtimeEventContract v1.
 * These string values are used on the wire (Mercure SSE) and must match
 * the frontend RealtimeEventType enum exactly.
 */
enum TipoDeEventoEnTiempoReal: string
{
    case TICKET_CREADO                     = 'ticket.created';
    case ESTADO_DE_TICKET_CAMBIADO         = 'ticket.status_changed';
    case TICKET_ASIGNADO                   = 'ticket.assigned';
    case MENSAJE_AGREGADO                  = 'message.added';
    case MENSAJE_ACTUALIZADO               = 'message.updated';
    case TICKET_LEIDO                      = 'ticket.read';
    case DISPONIBILIDAD_DE_AGENTE_CAMBIADA = 'agent.availability_changed';
    case TURNO_DE_AGENTE_CAMBIADO          = 'agent.shift_changed';
}
