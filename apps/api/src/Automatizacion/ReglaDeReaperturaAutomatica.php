<?php

namespace App\Automatizacion;

use App\Ticket\EventoDeTicket;
use App\Ticket\EstadoDeTicket;
use App\Ticket\TipoDeEventoDeTicket;
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
        // Solo admite mensajes entrantes en tickets resueltos
        if ($contexto->getTipoDeDisparador() !== 'inbound') {
            return false;
        }

        $ticket = $contexto->getTicket();
        // DEBE devolver false para tickets CLOSED - no se reabren los tickets cerrados
        if ($ticket->getEstado() === EstadoDeTicket::CERRADO->value) {
            return false;
        }

        return $ticket->getEstado() === EstadoDeTicket::RESUELTO->value;
    }

    public function execute(ContextoDeRegla $contexto): void
    {
        // Transición resolved → waiting (NO a active)
        $this->cambiarEstadoUseCase->execute(
            $contexto->getTicketId(),
            EstadoDeTicket::EN_ESPERA->value,
            false, // no fija first_response
            false  // no fija resolved
        );

        // Emite el evento auto_reopened
        $event = new EventoDeTicket($contexto->getTicketId(), TipoDeEventoDeTicket::REAPERTURA_AUTOMATICA, 'system');
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