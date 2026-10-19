<?php

namespace App\Asignacion;

class TicketRequiereAsignacionAutomaticaEvent
{
    public function __construct(
        private int $ticketId,
    ) {}

    public function getTicketId(): int
    {
        return $this->ticketId;
    }
}
