<?php

namespace App\Domain\Event;

use App\Domain\Message\MessageDirection;
use Symfony\Component\Uid\Ulid;

final readonly class MessageCreatedEvent extends DomainEvent
{
    public function __construct(
        Ulid $eventId,
        int $ticketId,
        \DateTimeImmutable $occurredAt,
        int $seq,
        int $version,
        public MessageDirection $direction,
        public int $messageId
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