<?php

declare(strict_types=1);

namespace App\Administracion\Query;

use App\Administracion\EspacioDeTrabajo;

/**
 * Data Transfer Object for workspace responses.
 */
class EspacioDeTrabajoDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $phoneNumberId,
        public readonly string $displayName,
        public readonly bool $isActive,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly int $agentCount = 0,
        public readonly bool $hasPending = false,
    ) {}

    public static function fromEntity(EspacioDeTrabajo $workspace, int $agentCount = 0): self
    {
        return new self(
            id: (int) $workspace->getId(),
            phoneNumberId: $workspace->getPhoneNumberId(),
            displayName: $workspace->getNombreVisible(),
            isActive: $workspace->isActivo(),
            createdAt: $workspace->getCreadoAt()->format('Y-m-d\TH:i:sP'),
            updatedAt: $workspace->getActualizadoAt()->format('Y-m-d\TH:i:sP'),
            agentCount: $agentCount,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'phone_number_id' => $this->phoneNumberId,
            'display_name' => $this->displayName,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'agent_count' => $this->agentCount,
            'has_pending' => $this->hasPending,
        ];
    }
}
