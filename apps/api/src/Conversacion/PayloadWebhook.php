<?php

declare(strict_types=1);

namespace App\Conversacion;

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
class PayloadWebhook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 50)]
    private string $tipoDeEvento;

    #[ORM\Column(name: 'payload', type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $recibidoAt;

    #[ORM\Column(name: 'phone_number_id', type: 'string', length: 36, nullable: true)]
    private ?string $phoneNumberId = null;

    #[ORM\Column(name: 'processed', type: 'boolean', options: ['default' => false])]
    private bool $procesado = false;

    public function __construct(string $eventType, array $payload, \DateTimeImmutable $receivedAt, ?string $phoneNumberId = null)
    {
        $this->tipoDeEvento = $eventType;
        $this->payload = $payload;
        $this->recibidoAt = $receivedAt;
        $this->phoneNumberId = $phoneNumberId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTipoDeEvento(): string
    {
        return $this->tipoDeEvento;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getRecibidoAt(): \DateTimeImmutable
    {
        return $this->recibidoAt;
    }

    public function getPhoneNumberId(): ?string
    {
        return $this->phoneNumberId;
    }

    public function setPhoneNumberId(?string $phoneNumberId): self
    {
        $this->phoneNumberId = $phoneNumberId;
        return $this;
    }

    public function isProcesado(): bool
    {
        return $this->procesado;
    }

    public function setProcesado(bool $processed): self
    {
        $this->procesado = $processed;

        return $this;
    }
}
