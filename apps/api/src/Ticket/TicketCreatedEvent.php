<?php

namespace App\Ticket;

use App\Compartido\DomainEvent;

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
            self::class,
            $ticketId,
            $occurredAt,
            $seq,
            $version
        );
    }
}