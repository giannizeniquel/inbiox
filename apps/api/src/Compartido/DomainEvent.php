<?php

namespace App\Compartido;

use Symfony\Component\Uid\Ulid;

abstract readonly class DomainEvent
{
    /**
     * @param string $type el nombre interno (`TipoDeEventoDeOutbox->value`) que se guarda en
     *                     `outbox_events.event_type` y en `payload.type`
     */
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