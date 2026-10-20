<?php

namespace App\Contacto\Query;

class ContactoDeListaDTO
{
    public ?int $ticketActivoId = null;

    public function __construct(
        public int $id,
        public string $telefono,
        public ?string $nombre,
        public ?string $estado,
        public ?string $canal,
        public ?int $responsableId,
        public ?string $email,
        public array $marcasDeTiempo,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'telefono' => $this->telefono,
            'nombre' => $this->nombre,
            'estado' => $this->estado,
            'canal' => $this->canal,
            'responsableId' => $this->responsableId,
            'email' => $this->email,
            'fechas' => $this->marcasDeTiempo,
            'ticketActivoId' => $this->ticketActivoId,
        ];
    }
}