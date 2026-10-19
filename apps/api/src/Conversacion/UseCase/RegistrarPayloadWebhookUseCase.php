<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Conversacion\PayloadWebhook;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists the raw webhook payload to webhook_payloads (T-02).
 *
 * It runs BEFORE dispatch so there is a record even if processing fails.
 * It extracts phone_number_id from metadata for multi-number traceability (CAP-7).
 */
final class RegistrarPayloadWebhookUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $payload decoded webhook body
     */
    public function execute(array $payload): void
    {
        $eventType = 'unknown';
        $phoneNumberId = null;

        // Determine event type and extract phoneNumberId from metadata
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (isset($value['messages'])) {
                    $eventType = 'message';
                }
                if (isset($value['statuses'])) {
                    $eventType = 'status';
                }
                // Extract phone_number_id from metadata (CAP-7 / META-017)
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
