<?php

namespace App\Conversacion;

enum DireccionDeMensaje: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
}