<?php

namespace App\Domain\Outbox;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_events')]
#[ORM\Index(name: 'idx_outbox_published', columns: ['published_at'])]
#[ORM\Index(name: 'idx_outbox_topic', columns: ['topic'])]
class OutboxEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'aggregate_type', type: 'string', length: 100)]
    private string $aggregateType;

    #[ORM\Column(name: 'aggregate_id', type: 'integer')]
    private int $aggregateId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $topic;

    #[ORM\Column(name: 'event_type', type: 'string', length: 100)]
    private string $eventType;

    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'retry_count', type: 'integer', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(name: 'status', type: 'string', length: 20, options: ['default' => 'pending'])]
    private string $status = 'pending';

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
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function markPublished(): self
    {
        $this->publishedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function incrementRetryCount(): self
    {
        $this->retryCount++;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isDeadLetter(): bool
    {
        return $this->status === 'dead_letter';
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