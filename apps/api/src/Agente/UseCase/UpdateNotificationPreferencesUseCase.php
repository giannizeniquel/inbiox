<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateNotificationPreferencesUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * A null preference is left unchanged.
     */
    public function execute(Agente $agent, ?bool $sound, ?bool $desktop): void
    {
        if ($sound !== null) {
            $agent->setSonidoDeNotificacion($sound);
        }
        if ($desktop !== null) {
            $agent->setNotificacionesDeEscritorio($desktop);
        }

        $this->entityManager->flush();
    }
}
