<?php

namespace App\Contacto\Query;

class EventoDeLineaDeTiempoDTO
{
    public function __construct(
        public string  $tipo,         // 'message.sent'|'message.received'|'ticket.created'|'ticket.status_changed'|'ticket.assigned'|'ticket.reassigned'
        public string  $ocurridoAt,   // ISO 8601
        public ?string $contenido,      // vista previa, truncada a 100 caracteres
        public ?int    $ticketId,
        public ?string $estadoDeTicket,
        public ?string $asignadoA,   // nombre del agente (asignaciones)
        public ?int    $agenteId = null, // ID del agente (solo asignaciones)
        public ?string $direccion,    // 'inbound'|'outbound' (solo mensajes)
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
