<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\EspacioDeTrabajo;

/**
 * Data Transfer Object para la respuesta de preferencia de espacio de trabajo activo.
 */
class EspacioDeTrabajoActivoDTO
{
    public function __construct(
        public readonly int $espacioDeTrabajoId,
        public readonly string $phoneNumberId,
        public readonly string $nombreVisible,
    ) {}

    public static function fromEntity(EspacioDeTrabajo $espacioDeTrabajo): self
    {
        return new self(
            espacioDeTrabajoId: (int) $espacioDeTrabajo->getId(),
            phoneNumberId: $espacioDeTrabajo->getPhoneNumberId(),
            nombreVisible: $espacioDeTrabajo->getNombreVisible(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->espacioDeTrabajoId,
            'phone_number_id' => $this->phoneNumberId,
            'display_name' => $this->nombreVisible,
        ];
    }
}
