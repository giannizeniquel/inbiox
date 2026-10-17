<?php

namespace App\Compartido;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "processed_events")]
#[ORM\UniqueConstraint(columns: ["event_id"])]
class ProcessedEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(name: "event_id", type: "string", length: 255, unique: true)]
    private string $eventId;

    #[ORM\Column(name: "processed_at", type: "datetime_immutable")]
    private \DateTimeImmutable $processedAt;

    #[ORM\Column(name: "result_data", type: "json", nullable: true)]
    private ?array $resultData = null;

    public function __construct(string $eventId)
    {
        $this->eventId = $eventId;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(\DateTimeImmutable $processedAt): self
    {
        $this->processedAt = $processedAt;
        return $this;
    }

    public function getResultData(): ?array
    {
        return $this->resultData;
    }

    public function setResultData(?array $resultData): self
    {
        $this->resultData = $resultData;
        return $this;
    }
}