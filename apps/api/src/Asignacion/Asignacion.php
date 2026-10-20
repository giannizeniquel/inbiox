<?php

declare(strict_types=1);

namespace App\Asignacion;

use App\Agente\Agente;
use App\Ticket\Ticket;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "assignments")]
#[ORM\Index(name: 'idx_assignments_ticket_id', columns: ['ticket_id'])]
#[ORM\Index(name: 'idx_assignments_assigned_at', columns: ['assigned_at'])]
#[ORM\Index(name: 'idx_assignments_agent_assigned_at', columns: ['agent_id', 'assigned_at'])]
class Asignacion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: "ticket_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private Ticket $ticket;

    #[ORM\ManyToOne(targetEntity: Agente::class)]
    #[ORM\JoinColumn(name: "agent_id", referencedColumnName: "id", nullable: true)]
    private ?Agente $agente = null;

    #[ORM\Column(name: "type", type: Types::STRING, length: 20, options: ['default' => 'manual'])]
    private string $tipo = 'manual';

    #[ORM\ManyToOne(targetEntity: Agente::class)]
    #[ORM\JoinColumn(name: "previous_agent_id", referencedColumnName: "id", nullable: true)]
    private ?Agente $agenteAnterior = null;

    #[ORM\Column(name: "assigned_at", type: "datetime_immutable")]
    private \DateTimeImmutable $asignadoAt;

    #[ORM\Column(name: "assigned_by", type: "string", length: 100)]
    private string $asignadoPor;

    #[ORM\Column(name: "notes", type: "text", nullable: true)]
    private ?string $notas = null;

    public function __construct(
        Ticket $ticket,
        ?Agente $agente = null,
        string $asignadoPor = '',
        ?string $notas = null,
        string $tipo = 'manual',
        ?Agente $agenteAnterior = null
    ) {
        $this->ticket = $ticket;
        $this->agente = $agente;
        $this->asignadoPor = $asignadoPor;
        $this->tipo = $tipo;
        $this->agenteAnterior = $agenteAnterior;
        $this->asignadoAt = new \DateTimeImmutable();
        $this->notas = $notas;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getAgente(): ?Agente
    {
        return $this->agente;
    }

    public function setAgente(?Agente $agente): self
    {
        $this->agente = $agente;
        return $this;
    }

    /**
     * Envoltorio de compatibilidad — devuelve el ID del agente desde la relación.
     */
    public function getAgenteId(): ?int
    {
        return $this->agente?->getId();
    }

    public function getAgenteAnterior(): ?Agente
    {
        return $this->agenteAnterior;
    }

    public function setAgenteAnterior(?Agente $agenteAnterior): self
    {
        $this->agenteAnterior = $agenteAnterior;
        return $this;
    }

    /**
     * Envoltorio de compatibilidad — devuelve el ID del agente anterior desde la relación.
     */
    public function getAgenteAnteriorId(): ?int
    {
        return $this->agenteAnterior?->getId();
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getAsignadoAt(): \DateTimeImmutable
    {
        return $this->asignadoAt;
    }

    public function getAsignadoPor(): string
    {
        return $this->asignadoPor;
    }

    public function setAsignadoPor(string $asignadoPor): self
    {
        $this->asignadoPor = $asignadoPor;
        return $this;
    }

    public function getNotas(): ?string
    {
        return $this->notas;
    }

    public function setNotas(?string $notas): self
    {
        $this->notas = $notas;
        return $this;
    }
}
