<?php

namespace App\Ticket;

use App\Compartido\DomainEvent;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;

use Symfony\Component\Uid\Ulid;

final readonly class InboxUpdatedEvent extends DomainEvent
{
    public function __construct(
        Ulid $eventId,
        int $ticketId,
        \DateTimeImmutable $occurredAt,
        int $seq,
        int $version
    ) {
        parent::__construct(
            $eventId,
            TipoDeEventoDeOutbox::BANDEJA_DE_TICKET_ACTUALIZADA->value,
            $ticketId,
            $occurredAt,
            $seq,
            $version
        );
    }
}