<?php

declare(strict_types=1);

namespace App\Disponibilidad\Query;

use App\Agente\Agent;

/**
 * Data Transfer Object for agent availability in workspace listing.
 */
class DisponibilidadDeAgenteDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly bool $isActivoAhora,
        public readonly int $cantidadDeTicketsActivos,
        public readonly int $maxTicketsActivos,
    ) {}

    public static function fromEntity(Agent $agente, int $cantidadDeTicketsActivos, bool $isActivoAhora): self
    {
        return new self(
            id: (int) $agente->getId(),
            nombre: $agente->getName(),
            isActivoAhora: $isActivoAhora,
            cantidadDeTicketsActivos: $cantidadDeTicketsActivos,
            maxTicketsActivos: $agente->getMaxActiveTickets(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->nombre,
            'is_active_now' => $this->isActivoAhora,
            'active_ticket_count' => $this->cantidadDeTicketsActivos,
            'max_active_tickets' => $this->maxTicketsActivos,
        ];
    }
}
