<?php

namespace App\Application\DTO;

class TicketDetailDTO
{
    public function __construct(
        public int $ticketId,
        public string $status,
        public string $priority,
        public array $contact,
        public ?array $owner,
        public array $timestamps,
    ) {}

    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'status' => $this->status,
            'priority' => $this->priority,
            'contact' => $this->contact,
            'owner' => $this->owner,
            'timestamps' => $this->timestamps,
        ];
    }
}
