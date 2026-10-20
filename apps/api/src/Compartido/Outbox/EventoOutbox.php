<?php

namespace App\Compartido\Outbox;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_events')]
#[ORM\Index(name: 'idx_outbox_published', columns: ['published_at'])]
#[ORM\Index(name: 'idx_outbox_topic', columns: ['topic'])]
class EventoOutbox
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'aggregate_type', type: 'string', length: 100)]
    private string $aggregateType;

    #[ORM\Column(name: 'aggregate_id', type: 'integer')]
    private int $aggregateId;

    #[ORM\Column(name: 'topic', type: 'string', length: 255)]
    private string $topic;

    #[ORM\Column(name: 'event_type', type: 'string', length: 100)]
    private string $tipoDeEvento;

    #[ORM\Column(name: 'payload', type: 'json')]
    private array $payload = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoAt;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publicadoAt = null;

    #[ORM\Column(name: 'retry_count', type: 'integer', options: ['default' => 0])]
    private int $reintentos = 0;

    #[ORM\Column(name: 'status', type: 'string', length: 20, options: ['default' => 'pending'])]
    private string $estado = 'pending';

    #[ORM\Column(name: 'sequence', type: 'integer', options: ['default' => 0])]
    private int $sequence = 0;

    public function __construct(
        string $aggregateType,
        int $aggregateId,
        string $topic,
        string $eventType,
        array $payload,
        ?int $sequence = 0
    ) {
        $this->id = (string) Ulid::generate();
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->topic = $topic;
        $this->tipoDeEvento = $eventType;
        $this->payload = $payload;
        $this->creadoAt = new \DateTimeImmutable();
        $this->sequence = $sequence ?? 0;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): int
    {
        return $this->aggregateId;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getTipoDeEvento(): string
    {
        return $this->tipoDeEvento;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }

    public function getPublicadoAt(): ?\DateTimeImmutable
    {
        return $this->publicadoAt;
    }

    public function marcarPublicado(): self
    {
        $this->publicadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getReintentos(): int
    {
        return $this->reintentos;
    }

    public function incrementarReintentos(): self
    {
        $this->reintentos++;
        return $this;
    }

    public function isPublicado(): bool
    {
        return $this->publicadoAt !== null;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $status): self
    {
        $this->estado = $status;
        return $this;
    }

    public function isDeadLetter(): bool
    {
        return $this->estado === 'dead_letter';
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): self
    {
        $this->sequence = $sequence;
        return $this;
    }
}