<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft delete: the agent stays, with active = false.
 */
final class DeactivateAgentUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(Agent $agent): void
    {
        $agent->setActive(false);
        $this->entityManager->flush();
    }
}
