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
            'ticketId' => $this->ticketId,
            'estado' => $this->estado,
            'prioridad' => $this->prioridad,
            'contacto' => $this->contacto,
            'ultimoMensaje' => $this->ultimoMensaje,
            'responsable' => $this->responsable,
            'metricas' => $this->metricas,
            'fechas' => $this->marcasDeTiempo,
        ];
    }
}
