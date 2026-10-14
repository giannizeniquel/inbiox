<?php

namespace App\Ticket\Query;

class TicketDetailDTO
{
    public function __construct(
        public int $ticketId,
        public string $status,
        public string $priority,
        public array $contact,
        public ?array $owner,
        public array $timestamps,
        public array $metrics = ['unread_count' => 0],
        public ?string $lastMessage = null,
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
            'metrics' => $this->metrics,
            'last_message' => $this->lastMessage,
        ];
    }
}
