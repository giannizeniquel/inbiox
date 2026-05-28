<?php

namespace App\Domain\Assignment;

use App\Domain\Ticket\Ticket;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "assignments")]
#[ORM\Index(name: 'idx_assignments_ticket_id', columns: ['ticket_id'])]
#[ORM\Index(name: 'idx_assignments_assigned_at', columns: ['assigned_at'])]
class Assignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: "ticket_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private Ticket $ticket;

    #[ORM\Column(name: "agent_id", type: "string", length: 100)]
    private string $agentId;

    #[ORM\Column(name: "assigned_at", type: "datetime_immutable")]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(name: "assigned_by", type: "string", length: 100)]
    private string $assignedBy;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $notes = null;

    public function __construct(
        Ticket $ticket,
        string $agentId,
        string $assignedBy,
        ?string $notes = null
    ) {
        $this->ticket = $ticket;
        $this->agentId = $agentId;
        $this->assignedBy = $assignedBy;
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

    public function getAgentId(): string
    {
        return $this->agentId;
    }

    public function setAgentId(string $agentId): self
    {
        $this->agentId = $agentId;
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
