<?php

namespace App\Domain\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a ticket needs auto-assignment
 */
class TicketAutoAssignmentRequestedEvent extends Event
{
    private int $ticketId;

    public function __construct(int $ticketId)
    {
        $this->ticketId = $ticketId;
    }

    public function getTicketId(): int
    {
        return $this->ticketId;
    }
}
