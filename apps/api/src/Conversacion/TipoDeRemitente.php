<?php

namespace App\Conversacion;

enum TipoDeRemitente: string
{
    case AGENTE = 'agent';
    case AUTOMATICO = 'automatic';
    case CONTACTO = 'contact';
}
