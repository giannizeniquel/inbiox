<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\EspacioDeTrabajo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft delete: the workspace stays, with is_active = false.
 */
final class DesactivarEspacioDeTrabajoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(EspacioDeTrabajo $workspace): void
    {
        $workspace->setActive(false);
        $this->entityManager->flush();
    }
}
