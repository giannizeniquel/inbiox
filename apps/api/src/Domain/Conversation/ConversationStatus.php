<?php

namespace App\Domain\Conversation;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}