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
            'phone' => $this->telefono,
            'name' => $this->nombre,
            'status' => $this->estado,
            'channel' => $this->canal,
            'owner_id' => $this->responsableId,
            'email' => $this->email,
            'timestamps' => $this->marcasDeTiempo,
            'activeTicketId' => $this->ticketActivoId,
        ];
    }
}