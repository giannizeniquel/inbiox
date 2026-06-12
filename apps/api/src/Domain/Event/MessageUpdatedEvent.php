<?php

namespace App\Domain\Event;

use Symfony\Component\Uid\Ulid;

/**
 * Stub domain event for message updates (delivered, read, etc.).
 *
 * Reserved for future use — currently no code emits this event.
 * The RealtimeEventType::MessageUpdated enum value exists so the
 * wire contract is stable before the event is actually published.
 */
final readonly class MessageUpdatedEvent extends DomainEvent
{
    public function __construct(
        Ulid $eventId,
        int $ticketId,
        \DateTimeImmutable $occurredAt,
        int $seq,
        int $version,
        public int $messageId,
        public ?string $status = null,
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
