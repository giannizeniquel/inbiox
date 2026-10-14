<?php

namespace App\Compartido;

use Symfony\Component\Uid\Ulid;

abstract readonly class DomainEvent
{
    public function __construct(
        public Ulid $eventId,
        public string $type,
        public int $ticketId,
        public \DateTimeImmutable $occurredAt,
        public int $seq,
        public int $version = 1
    ) {
    }

    public static function generateEventId(): Ulid
    {
        return new Ulid();
    }
}