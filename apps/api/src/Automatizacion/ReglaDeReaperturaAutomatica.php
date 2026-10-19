<?php

namespace App\Automatizacion;

use App\Ticket\EventoDeTicket;
use App\Ticket\EstadoDeTicket;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use Doctrine\ORM\EntityManagerInterface;

class ReglaDeReaperturaAutomatica implements ReglaInterface
{
    public function __construct(
        private CambiarEstadoDeTicketUseCase $cambiarEstadoUseCase,
        private EntityManagerInterface $em,
    ) {}

    public function supports(ContextoDeRegla $contexto): bool
    {
        // Only supports inbound messages on resolved tickets
        if ($contexto->getTipoDeDisparador() !== 'inbound') {
            return false;
        }

        $ticket = $contexto->getTicket();
        // MUST return false for CLOSED tickets - don't reopen closed tickets
        if ($ticket->getEstado() === EstadoDeTicket::CERRADO->value) {
            return false;
        }

        return $ticket->getEstado() === EstadoDeTicket::RESUELTO->value;
    }

    public function execute(ContextoDeRegla $contexto): void
    {
        // Transition resolved → waiting (NOT to active)
        $this->cambiarEstadoUseCase->execute(
            $contexto->getTicketId(),
            EstadoDeTicket::EN_ESPERA->value,
            false, // don't set first_response
            false  // don't set resolved
        );

        // Emit auto_reopened event
        $event = new EventoDeTicket($contexto->getTicketId(), 'auto_reopened', 'system');
        $this->em->persist($event);
        $this->em->flush();
    }

    public function getNombre(): string
    {
        return 'auto_reopen';
    }

    public function armarClaveDeEjecucion(ContextoDeRegla $contexto): string
    {
        $ticketId = $contexto->getTicketId();
        $mensajeId = $contexto->getMensajeId() ?? 'no_message';
        return "auto_reopen:{$ticketId}:{$mensajeId}";
    }
}