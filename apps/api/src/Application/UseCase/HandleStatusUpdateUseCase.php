<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Event\EventCollector;
use App\Domain\Event\MessageUpdatedEvent;
use App\Domain\Outbox\OutboxEvent;
use App\Repository\MessageRepository;
use App\Repository\TicketRepository;
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
class HandleStatusUpdateUseCase
{
    private const array STATUS_ORDER = [
        'sending' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
        'failed' => 99,
    ];

    private const array TERMINAL_STATUSES = ['read', 'failed'];

    private const array META_TO_INBIOX = [
        'sent' => 'sent',
        'delivered' => 'delivered',
        'read' => 'read',
        'failed' => 'failed',
    ];

    /** @var string|null Current phoneNumberId being processed (set during processStatusUpdate) */
    private ?string $currentPhoneNumberId = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageRepository $messageRepo,
        private readonly TicketRepository $ticketRepo,
        private readonly EventCollector $eventCollector,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process a single status update entry from a Meta webhook payload.
     *
     * @param array  $statusEntry The status entry from value.statuses[]
     * @param string|null $phoneNumberId Optional phone number ID from metadata (reserved for multi-number)
     */
    public function processStatusUpdate(array $statusEntry, ?string $phoneNumberId = null): void
    {
        // Store for outbox event payload inclusion (CAP-7 / META-019)
        $this->currentPhoneNumberId = $phoneNumberId;

        // 1. Extract fields
        $wamid = $statusEntry['id'] ?? null;
        $newStatusRaw = $statusEntry['status'] ?? null;
        $errors = $statusEntry['errors'] ?? [];

        if (!$wamid || !$newStatusRaw) {
            $this->logger->warning('Status update missing id or status field', [
                'entry' => $statusEntry,
            ]);

            return;
        }

        // 2. Map Meta status → Inbiox deliveryStatus
        $newStatus = self::META_TO_INBIOX[$newStatusRaw] ?? null;

        if (!$newStatus) {
            $this->logger->warning('Unknown Meta status value received', [
                'status' => $newStatusRaw,
                'wamid' => $wamid,
            ]);

            return;
        }

        // 3. Find Message by WAMID
        $message = $this->messageRepo->findByExternalId($wamid);

        if (!$message) {
            $this->logger->warning('Status update received for unknown WAMID', [
                'wamid' => $wamid,
                'status' => $newStatusRaw,
            ]);

            return;
        }

        // 4. Validate transition (forward-only FSM, terminal-aware)
        $currentStatus = $message->getDeliveryStatus();

        if (!$this->isValidTransition($currentStatus, $newStatus)) {
            $this->logger->info('Skipping invalid status transition', [
                'wamid' => $wamid,
                'from' => $currentStatus,
                'to' => $newStatus,
            ]);

            return;
        }

        // 5. Update Message entity
        $message->setDeliveryStatus($newStatus);

        if ($newStatus === 'failed' && !empty($errors)) {
            $message->setFailedReason($errors[0]['title'] ?? 'Unknown error');
        }

        $message->setUpdatedAt(new \DateTimeImmutable());

        // 6. Persist the message update
        $this->em->flush();

        // 7. Publish MessageUpdatedEvent via Outbox → Mercure
        $this->publishUpdatedEvent($message, $newStatus);
    }

    /**
     * Validate that a status transition is allowed by the FSM.
     *
     * Rules (AD-4):
     * - Terminal states (read, failed) allow NO transitions out
     * - 'failed' is reachable from ANY non-terminal state
     * - All other transitions must be strictly forward (order increasing)
     */
    private function isValidTransition(string $from, string $to): bool
    {
        // No transitions out of terminal states
        if (in_array($from, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        // Failed is reachable from any non-terminal state
        if ($to === 'failed') {
            return true;
        }

        // Forward-only: new status must have higher order
        $fromOrder = self::STATUS_ORDER[$from] ?? -1;
        $toOrder = self::STATUS_ORDER[$to] ?? -1;

        return $toOrder > $fromOrder;
    }

    /**
     * Persist a MessageUpdatedEvent to the outbox for Mercure delivery.
     */
    private function publishUpdatedEvent(\App\Domain\Message\Message $message, string $newStatus): void
    {
        // Resolve ticket ID through the conversation chain
        $conversation = $message->getConversation();

        if (!$conversation) {
            $this->logger->warning('Cannot publish MessageUpdatedEvent: message has no conversation', [
                'messageId' => $message->getId(),
                'wamid' => $message->getExternalId(),
            ]);

            return;
        }

        $ticket = $this->ticketRepo->findByConversation($conversation);

        if (!$ticket) {
            $this->logger->warning('Cannot publish MessageUpdatedEvent: conversation has no associated ticket', [
                'messageId' => $message->getId(),
                'conversationId' => $conversation->getId(),
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
            messageId: (int) $message->getId(),
            deliveryStatus: $newStatus,
            failedReason: $message->getFailedReason(),
        ));

        // Persist collected events to the outbox table
        $this->persistEventsToOutbox();
    }

    /**
     * Persist collected domain events from EventCollector to the OutboxEvent table.
     *
     * Pattern matches HandleInboundMessageUseCase::persistEventsToOutbox().
     */
    private function persistEventsToOutbox(): void
    {
        $events = $this->eventCollector->getEvents();

        if (empty($events)) {
            return;
        }

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

            $outboxEvent = new OutboxEvent(
                aggregateType: 'ticket',
                aggregateId: (int) $event->ticketId,
                topic: 'ticket/' . $event->ticketId,
                eventType: $event->type,
                payload: $payload,
                sequence: $event->seq,
            );

            $this->em->persist($outboxEvent);
        }

        $this->em->flush();
        $this->eventCollector->clear();
    }
}
