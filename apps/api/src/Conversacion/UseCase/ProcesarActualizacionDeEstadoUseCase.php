<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Compartido\RecolectorDeEventos;
use App\Conversacion\MessageUpdatedEvent;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Conversacion\Repository\MensajeRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Processes Meta WhatsApp status webhook entries.
 *
 * For each status entry from value.statuses[], this use case:
 * 1. Finds the Message by WAMID (externalId)
 * 2. Validates the status transition (forward-only FSM)
 * 3. Updates the Message deliveryStatus
 * 4. Publishes a MessageUpdatedEvent via Outbox → Mercure
 *
 * Design: AD-4 — forward-only status FSM with terminal read/failed.
 */
class ProcesarActualizacionDeEstadoUseCase
{
    private const ORDEN_DE_ESTADOS = [
        'sending' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
        'failed' => 99,
    ];

    private const ESTADOS_FINALES = ['read', 'failed'];

    private const META_TO_INBIOX = [
        'sent' => 'sent',
        'delivered' => 'delivered',
        'read' => 'read',
        'failed' => 'failed',
    ];

    /** @var string|null Current phoneNumberId being processed (set during procesarActualizacionDeEstado) */
    private ?string $currentPhoneNumberId = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MensajeRepository $mensajeRepo,
        private readonly TicketRepository $ticketRepo,
        private readonly RecolectorDeEventos $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process a single status update entry from a Meta webhook payload.
     *
     * @param array  $statusEntry The status entry from value.statuses[]
     * @param string|null $phoneNumberId Optional phone number ID from metadata (reserved for multi-number)
     */
    public function procesarActualizacionDeEstado(array $statusEntry, ?string $phoneNumberId = null): void
    {
        // Store for outbox event payload inclusion (CAP-7 / META-019)
        $this->currentPhoneNumberId = $phoneNumberId;

        // 1. Extract fields
        $wamid = $statusEntry['id'] ?? null;
        $nuevoEstadoCrudo = $statusEntry['status'] ?? null;
        $errors = $statusEntry['errors'] ?? [];

        if (!$wamid || !$nuevoEstadoCrudo) {
            $this->logger->warning('Status update missing id or status field', [
                'entry' => $statusEntry,
            ]);

            return;
        }

        // 2. Map Meta status → Inbiox deliveryStatus
        $nuevoEstado = self::META_TO_INBIOX[$nuevoEstadoCrudo] ?? null;

        if (!$nuevoEstado) {
            $this->logger->warning('Unknown Meta status value received', [
                'status' => $nuevoEstadoCrudo,
                'wamid' => $wamid,
            ]);

            return;
        }

        // 3. Find Message by WAMID
        $mensaje = $this->mensajeRepo->findByExternalId($wamid);

        if (!$mensaje) {
            $this->logger->warning('Status update received for unknown WAMID', [
                'wamid' => $wamid,
                'status' => $nuevoEstadoCrudo,
            ]);

            return;
        }

        // 4. Validate transition (forward-only FSM, terminal-aware)
        $estadoActual = $mensaje->getEstadoDeEntrega();

        if (!$this->isTransicionValida($estadoActual, $nuevoEstado)) {
            $this->logger->info('Skipping invalid status transition', [
                'wamid' => $wamid,
                'from' => $estadoActual,
                'to' => $nuevoEstado,
            ]);

            return;
        }

        // 5. Update Message entity
        $mensaje->setEstadoDeEntrega($nuevoEstado);

        if ($nuevoEstado === 'failed' && !empty($errors)) {
            $mensaje->setMotivoDeFallo($errors[0]['title'] ?? 'Unknown error');
        }

        $mensaje->setActualizadoAt(new \DateTimeImmutable());

        // 6. Persist the message update
        $this->em->flush();

        // 7. Publish MessageUpdatedEvent via Outbox → Mercure
        $this->registrarMessageUpdatedEvent($mensaje, $nuevoEstado);
    }

    /**
     * Validate that a status transition is allowed by the FSM.
     *
     * Rules (AD-4):
     * - Terminal states (read, failed) allow NO transitions out
     * - 'failed' is reachable from ANY non-terminal state
     * - All other transitions must be strictly forward (order increasing)
     */
    private function isTransicionValida(string $from, string $to): bool
    {
        // No transitions out of terminal states
        if (in_array($from, self::ESTADOS_FINALES, true)) {
            return false;
        }

        // Failed is reachable from any non-terminal state
        if ($to === 'failed') {
            return true;
        }

        // Forward-only: new status must have higher order
        $fromOrder = self::ORDEN_DE_ESTADOS[$from] ?? -1;
        $toOrder = self::ORDEN_DE_ESTADOS[$to] ?? -1;

        return $toOrder > $fromOrder;
    }

    /**
     * Persist a MessageUpdatedEvent to the outbox for Mercure delivery.
     */
    private function registrarMessageUpdatedEvent(\App\Conversacion\Mensaje $mensaje, string $nuevoEstado): void
    {
        // Resolve ticket ID through the conversation chain
        $conversacion = $mensaje->getConversacion();

        if (!$conversacion) {
            $this->logger->warning('Cannot publish MessageUpdatedEvent: message has no conversation', [
                'messageId' => $mensaje->getId(),
                'wamid' => $mensaje->getExternalId(),
            ]);

            return;
        }

        $ticket = $this->ticketRepo->findByConversacion($conversacion);

        if (!$ticket) {
            $this->logger->warning('Cannot publish MessageUpdatedEvent: conversation has no associated ticket', [
                'messageId' => $mensaje->getId(),
                'conversationId' => $conversacion->getId(),
            ]);

            return;
        }

        $ticketId = $ticket->getId();
        $seq = $ticket->incrementLastEventSeq();
        $occurredAt = new \DateTimeImmutable();

        // Collect the domain event
        $this->eventCollector->collect(new MessageUpdatedEvent(
            eventId: new Ulid(),
            ticketId: $ticketId,
            occurredAt: $occurredAt,
            seq: $seq,
            version: 1,
            messageId: (int) $mensaje->getId(),
            deliveryStatus: $nuevoEstado,
            failedReason: $mensaje->getMotivoDeFallo(),
        ));

        // Persist collected events to the outbox table
        $this->persistEventsToOutbox();
    }

    /**
     * Persist collected domain events from RecolectorDeEventos to the EventoOutbox table.
     *
     * Pattern matches ProcesarMensajeEntranteUseCase::persistEventsToOutbox().
     */
    private function persistEventsToOutbox(): void
    {
        $events = $this->eventCollector->getEvents();

        if (empty($events)) {
            return;
        }

        $outboxEvents = [];

        foreach ($events as $event) {
            $payload = [
                'event_id' => $event->eventId->toRfc4122(),
                'type' => $event->type,
                'ticket_id' => $event->ticketId,
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::RFC3339),
                'seq' => $event->seq,
                'version' => $event->version,
            ];

            if ($event instanceof MessageUpdatedEvent) {
                $payload['message_id'] = $event->messageId;
                $payload['delivery_status'] = $event->deliveryStatus;
                $payload['failed_reason'] = $event->failedReason;
                $payload['phone_number_id'] = $this->currentPhoneNumberId;
            }

            $outboxEvent = new EventoOutbox(
                aggregateType: 'ticket',
                aggregateId: (int) $event->ticketId,
                topic: 'tickets',
                eventType: $event->type,
                payload: $payload,
                sequence: $event->seq,
            );

            $outboxEvents[] = $outboxEvent;
        }

        $this->outboxWriter->write(...$outboxEvents);
        $this->eventCollector->clear();
    }
}
