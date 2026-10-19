<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft delete: the agent stays, with active = false.
 */
final class DesactivarAgenteUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(Agente $agente): void
    {
        $agente->setActivo(false);
        $this->entityManager->flush();
    }
}
