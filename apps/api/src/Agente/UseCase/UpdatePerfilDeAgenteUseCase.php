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
     * Establece la firma; null la borra.
     */
    public function execute(Agente $agente, ?string $firma): void
    {
        $agente->setFirma($firma);
        $this->entityManager->flush();
    }
}
