<?php

declare(strict_types=1);

namespace App\Asignacion;

use App\Agente\Agent;
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

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: "agent_id", referencedColumnName: "id", nullable: true)]
    private ?Agent $agente = null;

    #[ORM\Column(name: "type", type: Types::STRING, length: 20, options: ['default' => 'MANUAL_ASSIGN'])]
    private string $tipo = 'MANUAL_ASSIGN';

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: "previous_agent_id", referencedColumnName: "id", nullable: true)]
    private ?Agent $agenteAnterior = null;

    #[ORM\Column(name: "assigned_at", type: "datetime_immutable")]
    private \DateTimeImmutable $asignadoAt;

    #[ORM\Column(name: "assigned_by", type: "string", length: 100)]
    private string $asignadoPor;

    #[ORM\Column(name: "notes", type: "text", nullable: true)]
    private ?string $notas = null;

    public function __construct(
        Ticket $ticket,
        ?Agent $agent = null,
        string $assignedBy = '',
        ?string $notes = null,
        string $type = 'MANUAL_ASSIGN',
        ?Agent $previousAgent = null
    ) {
        $this->ticket = $ticket;
        $this->agente = $agent;
        $this->asignadoPor = $assignedBy;
        $this->tipo = $type;
        $this->agenteAnterior = $previousAgent;
        $this->asignadoAt = new \DateTimeImmutable();
        $this->notas = $notes;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getAgente(): ?Agent
    {
        return $this->agente;
    }

    public function setAgente(?Agent $agent): self
    {
        $this->agente = $agent;
        return $this;
    }

    /**
     * Compatibility wrapper — returns the agent ID from the relationship.
     */
    public function getAgenteId(): ?int
    {
        return $this->agente?->getId();
    }

    public function getAgenteAnterior(): ?Agent
    {
        return $this->agenteAnterior;
    }

    public function setAgenteAnterior(?Agent $previousAgent): self
    {
        $this->agenteAnterior = $previousAgent;
        return $this;
    }

    /**
     * Compatibility wrapper — returns the previous agent ID from the relationship.
     */
    public function getAgenteAnteriorId(): ?int
    {
        return $this->agenteAnterior?->getId();
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $type): self
    {
        $this->tipo = $type;
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

    public function setAsignadoPor(string $assignedBy): self
    {
        $this->asignadoPor = $assignedBy;
        return $this;
    }

    public function getNotas(): ?string
    {
        return $this->notas;
    }

    public function setNotas(?string $notes): self
    {
        $this->notas = $notes;
        return $this;
    }
}
