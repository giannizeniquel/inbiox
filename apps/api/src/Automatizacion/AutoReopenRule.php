<?php

namespace App\Automatizacion;

use App\Ticket\EventoDeTicket;
use App\Ticket\EstadoDeTicket;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use Doctrine\ORM\EntityManagerInterface;

class AutoReopenRule implements RuleInterface
{
    public function __construct(
        private CambiarEstadoDeTicketUseCase $transitionUseCase,
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
        if ($ticket->getStatus() === EstadoDeTicket::CLOSED->value) {
            return false;
        }

        return $ticket->getStatus() === EstadoDeTicket::RESOLVED->value;
    }

    public function execute(RuleContext $context): void
    {
        // Transition resolved → waiting (NOT to active)
        $this->transitionUseCase->execute(
            $context->getTicketId(),
            EstadoDeTicket::WAITING->value,
            false, // don't set first_response
            false  // don't set resolved
        );

        // Emit auto_reopened event
        $event = new EventoDeTicket($context->getTicketId(), 'auto_reopened', 'system');
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