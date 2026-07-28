<?php

namespace App\Application\DTO;

class TimelineEventDTO
{
    public function __construct(
        public string  $type,         // 'message.sent'|'message.received'|'ticket.created'|'ticket.status_changed'|'ticket.assigned'
        public string  $occurredAt,   // ISO 8601
        public ?string $content,      // preview, truncated to 100 chars
        public ?int    $ticketId,
        public ?string $ticketStatus,
        public ?string $assignedTo,   // agent name (assignments)
        public ?string $direction,    // 'inbound'|'outbound' (messages only)
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'occurred_at' => $this->occurredAt,
            'content' => $this->content,
            'ticket_id' => $this->ticketId,
            'ticket_status' => $this->ticketStatus,
            'assigned_to' => $this->assignedTo,
            'direction' => $this->direction,
        ];
    }
}
