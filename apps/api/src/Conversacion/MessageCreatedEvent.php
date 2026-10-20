<?php

namespace App\Conversacion;

use App\Compartido\DomainEvent;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;

use Symfony\Component\Uid\Ulid;

final readonly class MessageCreatedEvent extends DomainEvent
{
    public function __construct(
        Ulid $eventId,
        int $ticketId,
        \DateTimeImmutable $occurredAt,
        int $seq,
        int $version,
        public DireccionDeMensaje $direction,
        public int $messageId,
        public string $content = '',
        public ?string $senderName = null,
        public ?array $media = null,
    ) {
        parent::__construct(
            $eventId,
            TipoDeEventoDeOutbox::MENSAJE_CREADO->value,
            $ticketId,
            $occurredAt,
            $seq,
            $version
        );
    }
}