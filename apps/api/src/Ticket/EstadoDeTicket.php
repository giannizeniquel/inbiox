<?php

namespace App\Ticket;

enum EstadoDeTicket: string
{
    case NUEVO = 'new';
    case ACTIVO = 'active';
    case EN_ESPERA = 'waiting';
    case RESUELTO = 'resolved';
    case CERRADO = 'closed';
}
