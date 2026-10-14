<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft delete: the user stays, with is_active = false.
 */
final class DeactivateUserUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(User $user): void
    {
        $user->setIsActive(false);
        $this->entityManager->flush();
    }
}
