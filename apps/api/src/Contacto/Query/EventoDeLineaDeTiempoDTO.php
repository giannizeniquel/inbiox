<?php

namespace App\Contacto\Query;

class EventoDeLineaDeTiempoDTO
{
    public function __construct(
        public string  $tipo,         // 'ticket.creado'|'ticket.estado_cambiado'|'ticket.asignado'|'ticket.reasignado'|'ticket.' + tipo de `ticket_events` permitido
        public string  $ocurridoAt,   // ISO 8601
        public ?string $contenido,      // vista previa, truncada a 100 caracteres
        public ?int    $ticketId,
        public ?string $estadoDeTicket,
        public ?string $asignadoA,   // nombre del agente (asignaciones)
        public ?int    $agenteId = null, // ID del agente (solo asignaciones)
        public ?string $direccion,    // siempre null: la línea de tiempo no incluye mensajes
    ) {}

    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo,
            'ocurridoAt' => $this->ocurridoAt,
            'contenido' => $this->contenido,
            'ticketId' => $this->ticketId,
            'estadoDeTicket' => $this->estadoDeTicket,
            'asignadoA' => $this->asignadoA,
            'agenteId' => $this->agenteId,
            'direccion' => $this->direccion,
        ];
    }
}
