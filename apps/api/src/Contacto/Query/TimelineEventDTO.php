<?php

namespace App\Contacto\Query;

class TimelineEventDTO
{
    public function __construct(
        public string  $type,         // 'message.sent'|'message.received'|'ticket.created'|'ticket.status_changed'|'ticket.assigned'|'ticket.reassigned'
        public string  $occurredAt,   // ISO 8601
        public ?string $content,      // preview, truncated to 100 chars
        public ?int    $ticketId,
        public ?string $ticketStatus,
        public ?string $assignedTo,   // agent name (assignments)
        public ?int    $agentId = null, // agent ID (assignments only)
        public ?string $direction,    // 'inbound'|'outbound' (messages only)
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'occurredAt' => $this->occurredAt,
            'content' => $this->content,
            'ticketId' => $this->ticketId,
            'ticketStatus' => $this->ticketStatus,
            'assignedTo' => $this->assignedTo,
            'agentId' => $this->agentId,
            'direction' => $this->direction,
        ];
    }
}
