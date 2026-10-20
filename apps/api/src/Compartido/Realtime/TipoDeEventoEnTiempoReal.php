<?php

namespace App\Compartido\Realtime;

/**
 * Tipos de evento que viajan por Mercure (`docs/contrato-api.md`, sección 3.2).
 * Estos valores string se usan en el cable (Mercure SSE) y deben coincidir
 * exactamente con el enum TipoDeEventoEnTiempoReal del frontend.
 */
enum TipoDeEventoEnTiempoReal: string
{
    case TICKET_CREADO                     = 'ticket.creado';
    case ESTADO_DE_TICKET_CAMBIADO         = 'ticket.estado_cambiado';
    case TICKET_ASIGNADO                   = 'ticket.asignado';
    case MENSAJE_AGREGADO                  = 'mensaje.agregado';
    case MENSAJE_ACTUALIZADO               = 'mensaje.actualizado';
    case TICKET_LEIDO                      = 'ticket.leido';
    case DISPONIBILIDAD_DE_AGENTE_CAMBIADA = 'agente.disponibilidad_cambiada';
    case TURNO_DE_AGENTE_CAMBIADO          = 'agente.turno_cambiado';
}
