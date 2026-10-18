<?php

namespace App\Ticket\Query;

class DetalleDeTicketDTO
{
    public function __construct(
        public int $ticketId,
        public string $estado,
        public string $prioridad,
        public array $contacto,
        public ?array $responsable,
        public array $marcasDeTiempo,
        public array $metricas = ['unread_count' => 0],
        public ?string $ultimoMensaje = null,
    ) {}

    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'status' => $this->estado,
            'priority' => $this->prioridad,
            'contact' => $this->contacto,
            'owner' => $this->responsable,
            'timestamps' => $this->marcasDeTiempo,
            'metrics' => $this->metricas,
            'last_message' => $this->ultimoMensaje,
        ];
    }
}
