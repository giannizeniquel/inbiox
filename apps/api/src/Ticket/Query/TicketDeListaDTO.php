<?php

namespace App\Ticket\Query;

class TicketDeListaDTO
{
    public function __construct(
        public int $ticketId,
        public string $status,
        public string $priority,
        public array $contact,
        public ?array $lastMessage,
        public ?array $owner,
        public array $metrics,
        public array $timestamps,
    ) {}

    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'status' => $this->status,
            'priority' => $this->priority,
            'contact' => $this->contact,
            'last_message' => $this->lastMessage,
            'owner' => $this->owner,
            'metrics' => $this->metrics,
            'timestamps' => $this->timestamps,
        ];
    }
}
