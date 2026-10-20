<?php

namespace App\Compartido\Realtime;

/**
 * Enum respaldado de tipos de evento en tiempo real para el RealtimeEventContract v1.
 * Estos valores string se usan en el cable (Mercure SSE) y deben coincidir
 * exactamente con el enum RealtimeEventType del frontend.
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
