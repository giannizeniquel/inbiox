<?php

namespace App\Domain\Event;

use Symfony\Component\Uid\Ulid;

/**
 * Stub domain event for conversation metadata updates.
 *
 * Reserved for future use — currently no code emits this event.
 * The RealtimeEventType::ConversationUpdated enum value exists so the
 * wire contract is stable before the event is actually published.
 */
final readonly class ConversationUpdatedEvent extends DomainEvent
{
    public function __construct(
        Ulid $eventId,
        int $ticketId,
        \DateTimeImmutable $occurredAt,
        int $seq,
        int $version,
        public int $conversationId,
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
