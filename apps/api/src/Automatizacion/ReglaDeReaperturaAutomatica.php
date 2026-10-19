<?php

namespace App\Automatizacion;

use App\Ticket\EventoDeTicket;
use App\Ticket\EstadoDeTicket;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use Doctrine\ORM\EntityManagerInterface;

class ReglaDeReaperturaAutomatica implements ReglaInterface
{
    public function __construct(
        private CambiarEstadoDeTicketUseCase $transitionUseCase,
        private EntityManagerInterface $em,
    ) {}

    public function supports(ContextoDeRegla $context): bool
    {
        // Only supports inbound messages on resolved tickets
        if ($context->getTriggerType() !== 'inbound') {
            return false;
        }

        $ticket = $context->getTicket();
        // MUST return false for CLOSED tickets - don't reopen closed tickets
        if ($ticket->getEstado() === EstadoDeTicket::CERRADO->value) {
            return false;
        }

        return $ticket->getEstado() === EstadoDeTicket::RESUELTO->value;
    }

    public function execute(ContextoDeRegla $context): void
    {
        // Transition resolved → waiting (NOT to active)
        $this->transitionUseCase->execute(
            $context->getTicketId(),
            EstadoDeTicket::EN_ESPERA->value,
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

    public function buildExecutionKey(ContextoDeRegla $context): string
    {
        $ticketId = $context->getTicketId();
        $messageId = $context->getMessageId() ?? 'no_message';
        return "auto_reopen:{$ticketId}:{$messageId}";
    }
}