<?php

namespace App\Conversacion;

enum DireccionDeMensaje: string
{
    case ENTRANTE = 'entrante';
    case SALIENTE = 'saliente';
}
