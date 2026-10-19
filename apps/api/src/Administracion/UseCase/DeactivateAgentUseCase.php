<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft delete: the agent stays, with active = false.
 */
final class DeactivateAgentUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(Agente $agent): void
    {
        $agent->setActivo(false);
        $this->entityManager->flush();
    }
}
