<?php

namespace App\Conversacion;

enum DireccionDeMensaje: string
{
    case ENTRANTE = 'inbound';
    case SALIENTE = 'outbound';
}