<?php

namespace App\Conversacion;

enum EstadoDeConversacion: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}