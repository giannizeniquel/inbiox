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
     * A null $habilitado is left unchanged. The message is always set, so a null
     * message clears it.
     */
    public function execute(Agent $agente, ?bool $habilitado, ?string $mensaje): void
    {
        if ($habilitado !== null) {
            $agente->setAutoReplyEnabled($habilitado);
        }
        $agente->setAutoReplyMessage($mensaje);

        $this->entityManager->flush();
    }
}
