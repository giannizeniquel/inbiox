<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Workspace;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateWorkspaceUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Applies only the fields present in $data, checking them in order and
     * saving nothing if one fails.
     *
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is invalid
     */
    public function execute(Workspace $workspace, array $data): void
    {
        if (isset($data['phoneNumberId'])) {
            if (!\is_string($data['phoneNumberId']) || empty($data['phoneNumberId'])) {
                throw new \InvalidArgumentException('phoneNumberId must be a non-empty string');
            }
            $workspace->setPhoneNumberId($data['phoneNumberId']);
        }

        if (isset($data['displayName'])) {
            if (!\is_string($data['displayName']) || empty($data['displayName'])) {
                throw new \InvalidArgumentException('displayName must be a non-empty string');
            }
            $workspace->setDisplayName($data['displayName']);
        }

        if (isset($data['isActive'])) {
            $workspace->setActive((bool) $data['isActive']);
        }

        $this->entityManager->flush();
    }
}
