<?php

namespace App\Compartido;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "processed_events")]
#[ORM\UniqueConstraint(columns: ["event_id"])]
class EventoProcesado
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(name: "event_id", type: "string", length: 255, unique: true)]
    private string $eventoId;

    #[ORM\Column(name: "processed_at", type: "datetime_immutable")]
    private \DateTimeImmutable $procesadoAt;

    #[ORM\Column(name: "result_data", type: "json", nullable: true)]
    private ?array $datosDeResultado = null;

    public function __construct(string $eventId)
    {
        $this->eventoId = $eventId;
        $this->procesadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventoId(): string
    {
        return $this->eventoId;
    }

    public function getProcesadoAt(): \DateTimeImmutable
    {
        return $this->procesadoAt;
    }

    public function setProcesadoAt(\DateTimeImmutable $processedAt): self
    {
        $this->procesadoAt = $processedAt;
        return $this;
    }

    public function getDatosDeResultado(): ?array
    {
        return $this->datosDeResultado;
    }

    public function setDatosDeResultado(?array $resultData): self
    {
        $this->datosDeResultado = $resultData;
        return $this;
    }
}