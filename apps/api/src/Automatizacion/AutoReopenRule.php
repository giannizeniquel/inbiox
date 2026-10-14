<?php

namespace App\Automatizacion;

use App\Ticket\TicketEvent;
use App\Ticket\TicketStatus;
use App\Ticket\UseCase\TransitionTicketStatusUseCase;
use Doctrine\ORM\EntityManagerInterface;

class AutoReopenRule implements RuleInterface
{
    public function __construct(
        private TransitionTicketStatusUseCase $transitionUseCase,
        private EntityManagerInterface $em,
    ) {}

    public function supports(RuleContext $context): bool
    {
        // Only supports inbound messages on resolved tickets
        if ($context->getTriggerType() !== 'inbound') {
            return false;
        }

        $ticket = $context->getTicket();
        // MUST return false for CLOSED tickets - don't reopen closed tickets
        if ($ticket->getStatus() === TicketStatus::CLOSED->value) {
            return false;
        }

        return $ticket->getStatus() === TicketStatus::RESOLVED->value;
    }

    public function execute(RuleContext $context): void
    {
        // Transition resolved → waiting (NOT to active)
        $this->transitionUseCase->execute(
            $context->getTicketId(),
            TicketStatus::WAITING->value,
            false, // don't set first_response
            false  // don't set resolved
        );

        // Emit auto_reopened event
        $event = new TicketEvent($context->getTicketId(), 'auto_reopened', 'system');
        $this->em->persist($event);
        $this->em->flush();
    }

    public function getName(): string
    {
        return 'auto_reopen';
    }

    public function buildExecutionKey(RuleContext $context): string
    {
        $ticketId = $context->getTicketId();
        $messageId = $context->getMessageId() ?? 'no_message';
        return "auto_reopen:{$ticketId}:{$messageId}";
    }
}