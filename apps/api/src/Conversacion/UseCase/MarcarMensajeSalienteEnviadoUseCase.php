<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Conversacion\Repository\MensajeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Después de que Graph acepta un mensaje saliente, guarda su id de WhatsApp (WAMID)
 * para que los webhooks de estado puedan encontrarlo, y lo marca como enviado. Un mensaje
 * inexistente se ignora.
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
