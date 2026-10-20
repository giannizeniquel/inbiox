<?php

namespace App\Conversacion;

enum TipoDeRemitente: string
{
    case AGENTE = 'agente';
    case AUTOMATICO = 'automatico';
    case CONTACTO = 'contacto';
}
