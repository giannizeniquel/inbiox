<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Workspace\Workspace;

/**
 * Data Transfer Object for the active workspace preference response.
 */
class ActiveWorkspaceDTO
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $phoneNumberId,
        public readonly string $displayName,
    ) {}

    public static function fromEntity(Workspace $workspace): self
    {
        return new self(
            workspaceId: (int) $workspace->getId(),
            phoneNumberId: $workspace->getPhoneNumberId(),
            displayName: $workspace->getDisplayName(),
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
