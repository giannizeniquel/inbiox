<?php

declare(strict_types=1);

namespace App\Disponibilidad\Query;

use App\Agente\Agente;

/**
 * Data Transfer Object para la disponibilidad de agentes en el listado de un espacio de trabajo.
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

    public static function fromEntity(Agente $agente, int $cantidadDeTicketsActivos, bool $isActivoAhora): self
    {
        return new self(
            id: (int) $agente->getId(),
            nombre: $agente->getNombre(),
            isActivoAhora: $isActivoAhora,
            cantidadDeTicketsActivos: $cantidadDeTicketsActivos,
            maxTicketsActivos: $agente->getMaxTicketsActivos(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'activoAhora' => $this->isActivoAhora,
            'cantidadDeTicketsActivos' => $this->cantidadDeTicketsActivos,
            'maximoDeTicketsActivos' => $this->maxTicketsActivos,
        ];
    }
}
