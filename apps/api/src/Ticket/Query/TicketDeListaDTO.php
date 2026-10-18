<?php

namespace App\Ticket\Query;

class TicketDeListaDTO
{
    public function __construct(
        public int $ticketId,
        public string $estado,
        public string $prioridad,
        public array $contacto,
        public ?array $ultimoMensaje,
        public ?array $responsable,
        public array $metricas,
        public array $marcasDeTiempo,
    ) {}

    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'status' => $this->estado,
            'priority' => $this->prioridad,
            'contact' => $this->contacto,
            'last_message' => $this->ultimoMensaje,
            'owner' => $this->responsable,
            'metrics' => $this->metricas,
            'timestamps' => $this->marcasDeTiempo,
        ];
    }
}
