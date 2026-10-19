<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Conversacion\Repository\MensajeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * After Graph accepts an outbound message, stores its WhatsApp id (WAMID)
 * so status webhooks can find it, and marks it sent. A missing message is
 * ignored.
 */
final class MarcarMensajeSalienteEnviadoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MensajeRepository $mensajeRepository,
    ) {}

    public function execute(int|string $mensajeId, string $wamid): void
    {
        $mensaje = $this->mensajeRepository->find($mensajeId);
        if (!$mensaje) {
            return;
        }

        $mensaje->setExternalId($wamid);
        $mensaje->setEstadoDeEntrega('sent');
        $this->entityManager->flush();
    }
}
