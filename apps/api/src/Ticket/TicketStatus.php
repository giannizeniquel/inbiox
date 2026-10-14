<?php

namespace App\Ticket;

enum TicketStatus: string
{
    case NEW = 'new';
    case ACTIVE = 'active';
    case WAITING = 'waiting';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';
}
