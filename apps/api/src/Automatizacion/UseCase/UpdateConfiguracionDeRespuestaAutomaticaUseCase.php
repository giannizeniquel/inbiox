<?php

declare(strict_types=1);

namespace App\Automatizacion\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateConfiguracionDeRespuestaAutomaticaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Un $habilitado null queda sin cambios. El mensaje siempre se asigna, así que un
     * mensaje null lo borra.
     */
    public function execute(Agente $agente, ?bool $habilitado, ?string $mensaje): void
    {
        if ($habilitado !== null) {
            $agente->setRespuestaAutomaticaActiva($habilitado);
        }
        $agente->setMensajeDeRespuestaAutomatica($mensaje);

        $this->entityManager->flush();
    }
}
