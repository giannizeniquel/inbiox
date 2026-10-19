<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\EspacioDeTrabajo;

/**
 * Data Transfer Object for the active workspace preference response.
 */
class EspacioDeTrabajoActivoDTO
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $phoneNumberId,
        public readonly string $displayName,
    ) {}

    public static function fromEntity(EspacioDeTrabajo $workspace): self
    {
        return new self(
            workspaceId: (int) $workspace->getId(),
            phoneNumberId: $workspace->getPhoneNumberId(),
            displayName: $workspace->getNombreVisible(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'phone_number_id' => $this->phoneNumberId,
            'display_name' => $this->displayName,
        ];
    }
}
