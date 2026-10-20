<?php

declare(strict_types=1);

namespace App\Administracion\Query;

use App\Administracion\EspacioDeTrabajo;

/**
 * Data Transfer Object para las respuestas de espacios de trabajo.
 */
class EspacioDeTrabajoDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $phoneNumberId,
        public readonly string $nombreVisible,
        public readonly bool $activo,
        public readonly string $creadoAt,
        public readonly string $actualizadoAt,
        public readonly int $cantidadDeAgentes = 0,
        public readonly bool $hasPendientes = false,
    ) {}

    public static function fromEntity(EspacioDeTrabajo $espacioDeTrabajo, int $cantidadDeAgentes = 0): self
    {
        return new self(
            id: (int) $espacioDeTrabajo->getId(),
            phoneNumberId: $espacioDeTrabajo->getPhoneNumberId(),
            nombreVisible: $espacioDeTrabajo->getNombreVisible(),
            activo: $espacioDeTrabajo->isActivo(),
            creadoAt: $espacioDeTrabajo->getCreadoAt()->format('Y-m-d\TH:i:sP'),
            actualizadoAt: $espacioDeTrabajo->getActualizadoAt()->format('Y-m-d\TH:i:sP'),
            cantidadDeAgentes: $cantidadDeAgentes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'phoneNumberId' => $this->phoneNumberId,
            'nombreVisible' => $this->nombreVisible,
            'activo' => $this->activo,
            'creadoAt' => $this->creadoAt,
            'actualizadoAt' => $this->actualizadoAt,
            'cantidadDeAgentes' => $this->cantidadDeAgentes,
            'hayPendientes' => $this->hasPendientes,
        ];
    }
}
