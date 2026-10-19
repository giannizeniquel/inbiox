<?php

namespace App\Automatizacion;

use App\Ticket\Ticket;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rule_execution_logs')]
#[ORM\UniqueConstraint(columns: ['execution_key'])]
class RegistroDeRegla
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'execution_key', type: 'string', length: 255, unique: true)]
    private string $executionKey;

    #[ORM\Column(name: 'rule_name', type: 'string', length: 100)]
    private string $ruleName;

    #[ORM\Column(name: 'ticket_id', type: 'bigint', nullable: true)]
    private ?int $ticketId = null;

    #[ORM\Column(name: 'message_id', type: 'bigint', nullable: true)]
    private ?int $messageId = null;

    #[ORM\Column(name: 'executed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $executedAt;

    #[ORM\Column(name: 'result_status', type: 'string', length: 20)]
    private string $resultStatus = 'success';

    #[ORM\Column(name: 'result_data', type: 'json', nullable: true)]
    private ?array $resultData = null;

    public function __construct(string $executionKey, string $ruleName, ?int $ticketId = null, ?int $messageId = null)
    {
        $this->executionKey = $executionKey;
        $this->ruleName = $ruleName;
        $this->ticketId = $ticketId;
        $this->messageId = $messageId;
        $this->executedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExecutionKey(): string
    {
        return $this->executionKey;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function getTicketId(): ?int
    {
        return $this->ticketId;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    public function getExecutedAt(): \DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function getResultStatus(): string
    {
        return $this->resultStatus;
    }

    public function setResultStatus(string $resultStatus): self
    {
        $this->resultStatus = $resultStatus;
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