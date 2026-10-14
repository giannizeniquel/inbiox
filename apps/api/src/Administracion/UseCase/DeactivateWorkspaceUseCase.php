<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft delete: the workspace stays, with is_active = false.
 */
final class DeactivateWorkspaceUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(Workspace $workspace): void
    {
        $workspace->setActive(false);
        $this->entityManager->flush();
    }
}
