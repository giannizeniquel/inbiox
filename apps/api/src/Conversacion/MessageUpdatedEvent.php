<?php

namespace App\Conversacion;

use App\Compartido\DomainEvent;
use App\Compartido\RecolectorDeEventos;

use Symfony\Component\Uid\Ulid;

/**
 * Evento de dominio para los cambios de estado de un mensaje (delivered, read, failed, etc.).
 *
 * Se publica vía RecolectorDeEventos + Outbox → Mercure cuando el deliveryStatus de
 * un mensaje pasa a un nuevo estado vía ProcesarActualizacionDeEstadoUseCase.
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
