<?php

namespace App\Conversacion;

use App\Compartido\DomainEvent;

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
        public int $messageId,
        public string $content = '',
        public ?string $senderName = null,
        public ?array $media = null,
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