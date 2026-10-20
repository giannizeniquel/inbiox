<?php

namespace App\Ticket;

enum EstadoDeTicket: string
{
    case NUEVO = 'nuevo';
    case ACTIVO = 'activo';
    case EN_ESPERA = 'en_espera';
    case RESUELTO = 'resuelto';
    case CERRADO = 'cerrado';
}
