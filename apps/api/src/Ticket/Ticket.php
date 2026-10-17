<?php

namespace App\Ticket;

use App\Agente\Team;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Conversacion\RecipientPhoneNotFoundException;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "tickets")]
#[ORM\Index(name: 'idx_tickets_status', columns: ['status'])]
#[ORM\Index(name: 'idx_tickets_assigned_agent_id', columns: ['assigned_agent_id'])]
#[ORM\Index(name: 'idx_tickets_owner_status', columns: ['assigned_agent_id', 'status'])]
#[ORM\Index(name: 'idx_tickets_conversation_created_id', columns: ['conversation_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_tickets_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_tickets_status_last_inbound', columns: ['status', 'last_inbound_message_at'])]
#[ORM\Index(name: 'idx_tickets_team_id', columns: ['team_id'])]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: "contact_id", referencedColumnName: "id")]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: "conversation_id", referencedColumnName: "id")]
    private ?Conversation $conversation = null;

    #[ORM\Column(name: "status", type: "string", length: 20)]
    private string $status = EstadoDeTicket::NEW->value;

    #[ORM\Column(name: "priority", type: "string", length: 20)]
    private string $priority = "medium";

    #[ORM\Column(name: "assigned_agent_id", type: "bigint", nullable: true)]
    private ?int $assignedAgentId = null;

    #[ORM\Column(name: "first_response_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $firstResponseAt = null;

    #[ORM\Column(name: "resolved_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(name: "closed_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: "created_at", type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: "updated_at", type: "datetime_immutable")]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(name: "version", type: "integer", nullable: false, options: ["default" => 0])]
    private int $version = 0;

    #[ORM\Column(name: "last_event_seq", type: "integer", nullable: false, options: ["default" => 0])]
    private int $lastEventSeq = 0;

    #[ORM\Column(name: "phone_number_id", type: "string", length: 36, nullable: true)]
    private ?string $phoneNumberId = null;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: "team_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?Team $team = null;

    #[ORM\Column(name: "last_read_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    #[ORM\Column(name: "last_inbound_message_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $lastInboundMessageAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    /**
     * The WhatsApp number replies on this ticket go to.
     *
     * @throws RecipientPhoneNotFoundException when there is no contact or it has no phone
     */
    public function recipientPhone(): string
    {
        if (!$this->contact) {
            throw new RecipientPhoneNotFoundException('Ticket has no associated contact');
        }

        $phone = $this->contact->getPhone();
        if ($phone === null || $phone === '') {
            throw new RecipientPhoneNotFoundException('Contact has no phone number');
        }

        return $phone;
    }

    public function setContact(Contact $contact): self
    {
        $this->contact = $contact;
        return $this;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): self
    {
        $this->conversation = $conversation;
        return $this;
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

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function getAssignedAgentId(): ?int
    {
        return $this->assignedAgentId;
    }

    public function setAssignedAgentId(?int $assignedAgentId): self
    {
        $this->assignedAgentId = $assignedAgentId;
        return $this;
    }

    public function getFirstResponseAt(): ?\DateTimeImmutable
    {
        return $this->firstResponseAt;
    }

    public function setFirstResponseAt(?\DateTimeImmutable $firstResponseAt): self
    {
        $this->firstResponseAt = $firstResponseAt;
        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): self
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getLastEventSeq(): int
    {
        return $this->lastEventSeq;
    }

    public function setLastEventSeq(int $lastEventSeq): self
    {
        $this->lastEventSeq = $lastEventSeq;
        return $this;
    }

    public function incrementLastEventSeq(): int
    {
        $this->lastEventSeq++;
        return $this->lastEventSeq;
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

    public function getTeamId(): ?int
    {
        return $this->team?->getId();
    }

    public function setTeam(?Team $team): self
    {
        $this->team = $team;
        return $this;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function setLastReadAt(?\DateTimeImmutable $lastReadAt): self
    {
        $this->lastReadAt = $lastReadAt;
        return $this;
    }

    public function getLastInboundMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastInboundMessageAt;
    }

    public function setLastInboundMessageAt(?\DateTimeImmutable $lastInboundMessageAt): self
    {
        $this->lastInboundMessageAt = $lastInboundMessageAt;
        return $this;
    }

    public function markUpdated(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
