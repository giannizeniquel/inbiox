<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Conversacion\PayloadWebhook;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persiste el payload crudo del webhook en webhook_payloads (T-02).
 *
 * Se ejecuta ANTES del despacho, así queda un registro aunque el procesamiento falle.
 * Extrae phone_number_id de metadata para la trazabilidad multinúmero (CAP-7).
 */
final class RegistrarPayloadWebhookUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $payload cuerpo del webhook decodificado
     */
    public function execute(array $payload): void
    {
        $eventType = 'unknown';
        $phoneNumberId = null;

        // Determina el tipo de evento y extrae phoneNumberId de metadata
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (isset($value['messages'])) {
                    $eventType = 'message';
                }
                if (isset($value['statuses'])) {
                    $eventType = 'status';
                }
                // Extrae phone_number_id de metadata (CAP-7 / META-017)
                if ($phoneNumberId === null && isset($value['metadata']['phone_number_id'])) {
                    $phoneNumberId = $value['metadata']['phone_number_id'];
                }
            }
        }

        $payloadWebhook = new PayloadWebhook(
            tipoDeEvento: $eventType,
            payload: $payload,
            recibidoAt: new \DateTimeImmutable(),
            phoneNumberId: $phoneNumberId
        );

        $this->entityManager->persist($payloadWebhook);
        $this->entityManager->flush();
    }
}
