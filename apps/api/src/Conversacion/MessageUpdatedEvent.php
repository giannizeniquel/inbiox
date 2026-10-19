<?php

namespace App\Conversacion;

use App\Compartido\DomainEvent;
use App\Compartido\EventCollector;

use Symfony\Component\Uid\Ulid;

/**
 * Domain event for message status updates (delivered, read, failed, etc.).
 *
 * Published via EventCollector + Outbox → Mercure when a message's
 * deliveryStatus transitions to a new state via ProcesarActualizacionDeEstadoUseCase.
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
        public string $deliveryStatus,
        public ?string $failedReason = null,
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
