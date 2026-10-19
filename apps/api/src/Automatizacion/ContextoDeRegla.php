<?php

namespace App\Automatizacion;

use App\Conversacion\Message;
use App\Ticket\Ticket;

/**
 * Value object representing the context for rule execution.
 */
class ContextoDeRegla
{
    public function __construct(
        private Ticket $ticket,
        private ?Message $mensaje = null,
        private string $tipoDeDisparador = 'inbound'
    ) {}

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getMensaje(): ?Message
    {
        return $this->mensaje;
    }

    public function getTipoDeDisparador(): string
    {
        return $this->tipoDeDisparador;
    }

    public function getTicketId(): int
    {
        return $this->ticket->getId();
    }

    public function getMensajeId(): ?int
    {
        return $this->mensaje?->getId();
    }
}