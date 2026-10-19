<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class UpdatePerfilDeAgenteUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Sets the signature; null clears it.
     */
    public function execute(Agente $agente, ?string $firma): void
    {
        $agente->setFirma($firma);
        $this->entityManager->flush();
    }
}
