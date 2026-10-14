<?php

namespace App\Asignacion;

class TicketNeedsAutoAssignmentEvent
{
    public function __construct(
        private int $ticketId,
    ) {}

    public function getTicketId(): int
    {
        return $this->ticketId;
    }
}
