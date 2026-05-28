<?php

namespace App\Domain\Rule;

use App\Domain\Message\Message;
use App\Domain\Ticket\Ticket;

/**
 * Value object representing the context for rule execution.
 */
class RuleContext
{
    public function __construct(
        private Ticket $ticket,
        private ?Message $message = null,
        private string $triggerType = 'inbound'
    ) {}

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function getTicketId(): int
    {
        return $this->ticket->getId();
    }

    public function getMessageId(): ?int
    {
        return $this->message?->getId();
    }
}