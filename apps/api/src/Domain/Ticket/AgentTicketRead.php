<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agent_ticket_reads')]
#[ORM\UniqueConstraint(name: 'uniq_agent_ticket_reads', columns: ['agent_id', 'ticket_id'])]
#[ORM\Index(name: 'idx_agent_ticket_reads_agent', columns: ['agent_id'])]
#[ORM\Index(name: 'idx_agent_ticket_reads_ticket', columns: ['ticket_id'])]
class AgentTicketRead
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'agent_id', type: Types::BIGINT)]
    private int $agentId;

    #[ORM\Column(name: 'ticket_id', type: Types::BIGINT)]
    private int $ticketId;

    #[ORM\Column(name: 'last_read_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastReadAt;

    public function __construct(int $agentId, int $ticketId)
    {
        $this->agentId = $agentId;
        $this->ticketId = $ticketId;
        $this->lastReadAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgentId(): int
    {
        return $this->agentId;
    }

    public function getTicketId(): int
    {
        return $this->ticketId;
    }

    public function getLastReadAt(): \DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function touch(): self
    {
        $this->lastReadAt = new \DateTimeImmutable();
        return $this;
    }
}
