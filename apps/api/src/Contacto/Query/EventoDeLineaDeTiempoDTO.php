<?php

namespace App\Contacto\Query;

class EventoDeLineaDeTiempoDTO
{
    public function __construct(
        public string  $tipo,         // 'message.sent'|'message.received'|'ticket.created'|'ticket.status_changed'|'ticket.assigned'|'ticket.reassigned'
        public string  $ocurridoAt,   // ISO 8601
        public ?string $contenido,      // preview, truncated to 100 chars
        public ?int    $ticketId,
        public ?string $estadoDeTicket,
        public ?string $asignadoA,   // agent name (assignments)
        public ?int    $agenteId = null, // agent ID (assignments only)
        public ?string $direccion,    // 'inbound'|'outbound' (messages only)
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->tipo,
            'occurredAt' => $this->ocurridoAt,
            'content' => $this->contenido,
            'ticketId' => $this->ticketId,
            'ticketStatus' => $this->estadoDeTicket,
            'assignedTo' => $this->asignadoA,
            'agentId' => $this->agenteId,
            'direction' => $this->direccion,
        ];
    }
}
