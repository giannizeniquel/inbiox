<?php

namespace App\Conversacion;

enum EstadoDeConversacion: string
{
    case ABIERTA = 'open';
    case CERRADA = 'closed';
}