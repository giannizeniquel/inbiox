<?php

namespace App\Domain\Event;

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
            self::class,
            $ticketId,
            $occurredAt,
            $seq,
            $version
        );
    }
}