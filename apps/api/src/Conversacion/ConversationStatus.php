<?php

namespace App\Conversacion;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}