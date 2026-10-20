<?php

namespace App\Automatizacion;

use App\Conversacion\Mensaje;
use App\Ticket\Ticket;

/**
 * Value object que representa el contexto de ejecución de una regla.
 */
class ContextoDeRegla
{
    public function __construct(
        private Ticket $ticket,
        private ?Mensaje $mensaje = null,
        private string $tipoDeDisparador = 'inbound'
    ) {}

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getMensaje(): ?Mensaje
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