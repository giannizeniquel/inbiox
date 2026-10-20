<?php

namespace App\Ticket;

use App\Compartido\DomainEvent;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;

use Symfony\Component\Uid\Ulid;

final readonly class TicketCreatedEvent extends DomainEvent
{
    public function __construct(
        Ulid $eventId,
        int $ticketId,
        \DateTimeImmutable $occurredAt,
        int $seq,
        int $version,
        public EstadoDeTicket $status,
        public int $priority
    ) {
        parent::__construct(
            $eventId,
            TipoDeEventoDeOutbox::TICKET_CREADO->value,
            $ticketId,
            $occurredAt,
            $seq,
            $version
        );
    }
}