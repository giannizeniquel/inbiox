<?php

namespace App\Domain\Message;

enum MessageSenderType: string
{
    case AGENT = 'agent';
    case AUTOMATIC = 'automatic';
    case CONTACT = 'contact';
}
