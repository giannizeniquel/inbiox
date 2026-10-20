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
        public array $metricas = ['cantidadDeNoLeidos' => 0],
        public ?string $ultimoMensaje = null,
    ) {}

    public function toArray(): array
    {
        return [
            'ticketId' => $this->ticketId,
            'estado' => $this->estado,
            'prioridad' => $this->prioridad,
            'contacto' => $this->contacto,
            'responsable' => $this->responsable,
            'fechas' => $this->marcasDeTiempo,
            'metricas' => $this->metricas,
            'ultimoMensaje' => $this->ultimoMensaje,
        ];
    }
}
