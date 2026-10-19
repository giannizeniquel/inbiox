<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class UpdatePreferenciasDeNotificacionUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * A null preference is left unchanged.
     */
    public function execute(Agente $agente, ?bool $sonido, ?bool $escritorio): void
    {
        if ($sonido !== null) {
            $agente->setSonidoDeNotificacion($sonido);
        }
        if ($escritorio !== null) {
            $agente->setNotificacionesDeEscritorio($escritorio);
        }

        $this->entityManager->flush();
    }
}
