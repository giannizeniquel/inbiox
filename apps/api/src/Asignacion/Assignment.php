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
class Assignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: "ticket_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private Ticket $ticket;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: "agent_id", referencedColumnName: "id", nullable: true)]
    private ?Agent $agent = null;

    #[ORM\Column(name: "type", type: Types::STRING, length: 20, options: ['default' => 'MANUAL_ASSIGN'])]
    private string $type = 'MANUAL_ASSIGN';

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: "previous_agent_id", referencedColumnName: "id", nullable: true)]
    private ?Agent $previousAgent = null;

    #[ORM\Column(name: "assigned_at", type: "datetime_immutable")]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(name: "assigned_by", type: "string", length: 100)]
    private string $assignedBy;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $notes = null;

    public function __construct(
        Ticket $ticket,
        ?Agent $agent = null,
        string $assignedBy = '',
        ?string $notes = null,
        string $type = 'MANUAL_ASSIGN',
        ?Agent $previousAgent = null
    ) {
        $this->ticket = $ticket;
        $this->agent = $agent;
        $this->assignedBy = $assignedBy;
        $this->type = $type;
        $this->previousAgent = $previousAgent;
        $this->assignedAt = new \DateTimeImmutable();
        $this->notes = $notes;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    public function setAgent(?Agent $agent): self
    {
        $this->agent = $agent;
        return $this;
    }

    /**
     * Compatibility wrapper — returns the agent ID from the relationship.
     */
    public function getAgentId(): ?int
    {
        return $this->agent?->getId();
    }

    public function getPreviousAgent(): ?Agent
    {
        return $this->previousAgent;
    }

    public function setPreviousAgent(?Agent $previousAgent): self
    {
        $this->previousAgent = $previousAgent;
        return $this;
    }

    /**
     * Compatibility wrapper — returns the previous agent ID from the relationship.
     */
    public function getPreviousAgentId(): ?int
    {
        return $this->previousAgent?->getId();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getAssignedBy(): string
    {
        return $this->assignedBy;
    }

    public function setAssignedBy(string $assignedBy): self
    {
        $this->assignedBy = $assignedBy;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }
}
