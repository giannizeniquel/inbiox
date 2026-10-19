<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateAgentProfileUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Sets the signature; null clears it.
     */
    public function execute(Agente $agent, ?string $signature): void
    {
        $agent->setFirma($signature);
        $this->entityManager->flush();
    }
}
