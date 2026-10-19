<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class UnlinkUserFromAgentUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @throws \InvalidArgumentException when the agent has no linked user
     */
    public function execute(Agente $agent): void
    {
        $user = $agent->getUser();

        if (null === $user) {
            throw new \InvalidArgumentException('Agent has no linked user');
        }

        $agent->setUser(null);
        $user->setAgent(null);
        $this->entityManager->flush();
    }
}
