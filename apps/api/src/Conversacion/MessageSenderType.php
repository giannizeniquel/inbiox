<?php

namespace App\Conversacion;

enum MessageSenderType: string
{
    case AGENT = 'agent';
    case AUTOMATIC = 'automatic';
    case CONTACT = 'contact';
}
