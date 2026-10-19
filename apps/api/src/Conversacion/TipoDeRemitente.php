<?php

namespace App\Conversacion;

enum TipoDeRemitente: string
{
    case AGENT = 'agent';
    case AUTOMATIC = 'automatic';
    case CONTACT = 'contact';
}
