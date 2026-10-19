<?php

declare(strict_types=1);

namespace App\Automatizacion\UseCase;

use App\Agente\Agent;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateConfiguracionDeRespuestaAutomaticaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * A null $enabled is left unchanged. The message is always set, so a null
     * message clears it.
     */
    public function execute(Agent $agent, ?bool $enabled, ?string $message): void
    {
        if ($enabled !== null) {
            $agent->setAutoReplyEnabled($enabled);
        }
        $agent->setAutoReplyMessage($message);

        $this->entityManager->flush();
    }
}
