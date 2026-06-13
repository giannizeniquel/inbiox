<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use Doctrine\ORM\Mapping as ORM;

/**
 * Raw webhook payload storage for debugging and auditing.
 *
 * Payloads are persisted BEFORE processing to ensure we have a record
 * even if the processor crashes or the handler throws an exception.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_payloads')]
#[ORM\Index(name: 'idx_webhook_payloads_event_type', columns: ['event_type'])]
#[ORM\Index(name: 'idx_webhook_payloads_received_at', columns: ['received_at'])]
class WebhookPayload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 50)]
    private string $eventType;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $processed = false;

    public function __construct(string $eventType, array $payload, \DateTimeImmutable $receivedAt)
    {
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->receivedAt = $receivedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function setProcessed(bool $processed): self
    {
        $this->processed = $processed;

        return $this;
    }
}
