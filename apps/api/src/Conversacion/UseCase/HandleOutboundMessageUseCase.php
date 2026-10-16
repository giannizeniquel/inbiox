<?php

namespace App\Conversacion\UseCase;

use App\Asignacion\UseCase\AssignTicketUseCase;
use App\Asignacion\UseCase\AutoAssignTicketUseCase;
use App\Ticket\UseCase\TransitionTicketStatusUseCase;

use App\Asignacion\AssignmentService;
use App\Asignacion\AssignmentType;
use App\Conversacion\Conversation;
use App\Conversacion\ConversationStatus;
use App\Compartido\EventCollector;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketUpdatedEvent;
use App\Conversacion\MessageCreatedEvent;
use App\Conversacion\Message;
use App\Conversacion\MessageDirection;
use App\Conversacion\MessageSenderType;
use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Compartido\ProcessedEvent;
use App\Ticket\Ticket;
use App\Ticket\TicketStatus;
use App\Automatizacion\RuleExecutorService;
use App\Automatizacion\RuleContext;
use App\Conversacion\ConversationWindowPolicy;
use App\Ticket\TicketOwnershipGuard;
use App\Compartido\ConcurrencyException;
use App\Conversacion\ConversationWindowExpiredException;
use App\Compartido\StateConflictException;
use App\Agente\Repository\AgentRepository;
use App\Conversacion\Repository\ConversationRepository;
use App\Conversacion\Repository\MessageRepository;
use App\Compartido\Repository\ProcessedEventRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Ulid;

/**
 * Handles outbound (agent) messages.
 * Transitions ticket status based on FSM rules.
 * Sets first_response_at on first agent reply.
 * Implements idempotency: duplicate messages are skipped.
 */
class HandleOutboundMessageUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConversationRepository $conversationRepo,
        private MessageRepository $messageRepo,
        private TicketRepository $ticketRepo,
        private TransitionTicketStatusUseCase $transitionUseCase,
        private ProcessedEventRepository $processedEventRepo,
        private RuleExecutorService $ruleExecutor,
        private AssignmentService $assignmentService,
        private AgentRepository $agentRepo,
        private EventCollector $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private ConversationWindowPolicy $windowPolicy,
    ) {}

    /**
     * Process outbound message from agent
     * Implements idempotency: duplicate messages (same ticket + content) are skipped.
     * Implements optimistic locking retry with exponential backoff.
     *
     * @param int $ticketId
     * @param string $content
     * @param int $agentId
     * @param array|null $mediaMetadata Optional media metadata: {key, mimeType, filename, size, kind, providerId, caption}
     * @return array
     */
    public function processOutboundMessage(
        int $ticketId,
        string $content,
        int $agentId,
        ?array $mediaMetadata = null,
        bool $senderIsPrivileged = false,
    ): array {
        // Generate deterministic event key: outbound:{ticketId}:{contentHash}
        // Media messages have empty content (caption lives in media metadata), so
        // hash the provider media ID (unique per upload) to avoid dedup collisions.
        $mediaId = $mediaMetadata['providerId'] ?? null;
        $hashSource = $mediaId !== null ? 'media:' . $mediaId : 'text:' . $content;
        $contentHash = md5($hashSource);
        $eventKey = sprintf('outbound:%d:%s', $ticketId, $contentHash);

        // Check idempotency: return early if already processed (no retry needed)
        $existingEvent = $this->processedEventRepo->findByEventId($eventKey);
        if ($existingEvent !== null) {
            return [
                'ticket_id' => $ticketId,
                'message_id' => null,
                'previous_status' => null,
                'new_status' => null,
                'first_response_set' => false,
                'transition' => 'none',
                'status' => 'duplicate_skipped',
                'event_key' => $eventKey,
                'cached_result' => $existingEvent->getResultData(),
            ];
        }

        // Retry logic for optimistic locking
        $maxRetries = 3;
        $attempt = 0;
        $ticket = null;
        $result = null;

        while ($attempt < $maxRetries) {
            try {
                $result = $this->executeTransactionWithRetry($ticketId, $content, $eventKey, $agentId, $ticket, $mediaMetadata, $senderIsPrivileged);
                break;
            } catch (OptimisticLockException $e) {
                // A rolled-back attempt must not leak its collected events
                // (e.g. TicketAssigned) into the next attempt's flush —
                // otherwise the retry re-collects the same events again,
                // producing a phantom event with a duplicate seq
                // (design.md D6).
                $this->eventCollector->clear();

                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw new ConcurrencyException(
                        'Max retries exceeded for optimistic locking on ticket ' . $ticketId,
                        $e
                    );
                }

                // Exponential backoff: 100ms, 200ms, 400ms
                $sleepMs = $attempt * $attempt * 100;
                usleep($sleepMs * 1000);

                // Refresh ticket state before retry
                if ($ticket !== null) {
                    $this->em->refresh($ticket);
                }

                // Clear entity manager to avoid stale state
                $this->em->clear();
            } catch (\Throwable $e) {
                // Any other abort (e.g. StateConflictException,
                // ConversationWindowExpiredException) must also discard
                // whatever this attempt collected before propagating —
                // there is no retry for these, but the collector must not
                // carry stale events into some later, unrelated call
                // (design.md D6).
                $this->eventCollector->clear();
                throw $e;
            }
        }

        if ($result === null) {
            // Should never reach here, but satisfy static analysis
            throw new ConcurrencyException('Unexpected error in retry loop');
        }

        // Persist events AFTER the transaction fully commits (moved OUT of
        // the try block, matching AssignTicketUseCase:118-121 /
        // AutoAssignTicketUseCase:55-62): keeping the outbox flush inside
        // the try let an outbox-flush OptimisticLockException re-execute
        // the WHOLE transaction and re-collect duplicate events
        // (design.md D6).
        $this->persistEventsToOutbox();

        return $result;
    }

    /**
     * Runs one attempt of the outbound flow inside a transaction. $ticket is
     * set as soon as the row is locked, so the retry loop can refresh it.
     */
    private function executeTransactionWithRetry(
        int $ticketId,
        string $content,
        string $eventKey,
        int $agentId,
        ?Ticket &$ticket,
        ?array $mediaMetadata = null,
        bool $senderIsPrivileged = false,
    ): array {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $content, $eventKey, $agentId, &$ticket, $mediaMetadata, $senderIsPrivileged) {
            // Get ticket — pessimistic locking read as the FIRST statement of
            // the transaction (design.md D1). Under MySQL REPEATABLE READ, a
            // plain findById() opens a consistent-read snapshot BEFORE any
            // lock is acquired; a later refresh() would still read that
            // stale snapshot. A locking read here blocks until any
            // conflicting transaction commits, then HINT_REFRESH hydrates
            // from the latest-committed row.
            $ticket = $this->ticketRepo->findByIdForUpdate($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
            }

            $skipOwnershipChange = TicketOwnershipGuard::isResolvedOrClosed($ticket->getStatus());
            $this->guardSend($ticket, $ticketId, $agentId, $senderIsPrivileged);

            // Get conversation
            $conversation = $ticket->getConversation();
            if (!$conversation) {
                throw new \InvalidArgumentException('Ticket has no conversation');
            }

            // Skipped on resolved/closed tickets — ownership is frozen there.
            if (!$skipOwnershipChange) {
                $this->claimTicketForReplyingAgent($ticket, $agentId);
            }

            $msg = $this->createOutboundMessage($conversation, $content, $agentId, $mediaMetadata);

            [$currentStatus, $newStatus, $transition, $shouldSetFirstResponse] = $this->applyReplyTransition($ticket, $ticketId);

            // Update conversation timestamps
            $conversation->setLastMessageAt($msg->getCreatedAt());
            $conversation->setUpdatedAt(new \DateTimeImmutable());
            $ticket->markUpdated();

            // Store processed event for idempotency
            $resultData = [
                'ticket_id' => $ticketId,
                'message_id' => $msg->getId(),
                'previous_status' => $currentStatus,
                'new_status' => $newStatus,
                'first_response_set' => $shouldSetFirstResponse,
                'transition' => $transition ?? 'none',
            ];
            $processedEvent = new ProcessedEvent($eventKey);
            $processedEvent->setResultData($resultData);
            $this->em->persist($processedEvent);

            $this->em->flush();

            $this->collectMessageCreatedEvent($ticket, $ticketId, $msg, $content, $agentId);

            // Flush again to persist the bumped lastEventSeq (design.md D5).
            $this->em->flush();

            // Execute automation rules (outbound trigger)
            $ruleContext = new RuleContext($ticket, $msg, 'outbound');
            $this->ruleExecutor->execute($ruleContext);

            return [
                'ticket_id' => $ticketId,
                'message_id' => $msg->getId(),
                'previous_status' => $currentStatus,
                'new_status' => $newStatus,
                'first_response_set' => $shouldSetFirstResponse,
                'transition' => $transition ?? 'none',
                'status' => 'ok',
                'event_key' => $eventKey,
            ];
        });
    }

    /**
     * Rejects the send before anything is persisted.
     *
     * Resolved/closed ownership guard (design.md D3-D5, spec.md
     * "Resolved/Closed Ticket Ownership Guard"): a non-owning, non-privileged
     * agent may not claim/reassign a resolved/closed ticket by replying. It
     * runs BEFORE the window-policy check — a 409 here is more informative
     * than a misleading 422, and ownership on a closed ticket is
     * stable/authoritative while the window is merely time-dependent.
     *
     * WhatsApp 24h conversation-window guard-before-persist (design.md D3/D4):
     * free text is rejected once the effective window has elapsed, before any
     * message row is persisted and before any FSM transition — never a
     * phantom "sent" message.
     */
    private function guardSend(Ticket $ticket, int $ticketId, int $agentId, bool $senderIsPrivileged): void
    {
        $status = $ticket->getStatus();
        if (TicketOwnershipGuard::blocksReassignment($status, $senderIsPrivileged, $agentId, $ticket->getAssignedAgentId())) {
            throw new StateConflictException(sprintf(
                'Cannot send to ticket in "%s" state: sender is not the assigned owner',
                $status
            ));
        }

        if (!$this->windowPolicy->isOpenForSending($ticket->getLastInboundMessageAt(), new \DateTimeImmutable())) {
            throw new ConversationWindowExpiredException($ticketId, $ticket->getLastInboundMessageAt());
        }
    }

    /**
     * Assigns the ticket to the replying agent when someone else (or nobody)
     * owns it. An unknown agent, or agent id 0, leaves the ticket as it is.
     */
    private function claimTicketForReplyingAgent(Ticket $ticket, int $agentId): void
    {
        $currentAssigneeId = $ticket->getAssignedAgentId();
        if ($agentId <= 0 || $currentAssigneeId === $agentId) {
            return;
        }

        $agent = $this->agentRepo->findById($agentId);
        if ($agent === null) {
            return;
        }

        $previousAgent = null;
        if ($currentAssigneeId !== null) {
            $previousAgent = $this->agentRepo->findById($currentAssigneeId);
        }

        $type = $currentAssigneeId !== null
            ? AssignmentType::REASSIGN
            : AssignmentType::AUTO_ASSIGN;

        $this->assignmentService->recordOwnershipChange(
            ticket: $ticket,
            agent: $agent,
            previousAgent: $previousAgent,
            type: $type,
            assignedBy: 'system',
            notes: 'Auto-assigned on agent reply',
        );

        // Flush the assignment + any status change made by AssignmentService
        // (e.g. new → active) so the DB is consistent before we decide the
        // FSM transition below.
        $this->em->flush();
        $this->em->refresh($ticket);

        // When an agent replies and becomes the owner, mark as read
        $ticket->setLastReadAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function createOutboundMessage(Conversation $conversation, string $content, int $agentId, ?array $mediaMetadata): Message
    {
        $msg = new Message();
        $msg->setConversation($conversation);
        $msg->setDirection('outbound');
        $msg->setContent($content);
        $msg->setSenderAgentId($agentId > 0 ? $agentId : null);
        $msg->setSenderType(MessageSenderType::AGENT);

        // Attach media metadata if provided
        if ($mediaMetadata !== null) {
            $msg->setMedia($mediaMetadata);
        }

        $this->em->persist($msg);

        return $msg;
    }

    /**
     * Moves the ticket after an agent reply (new → active, active → waiting,
     * waiting stays) and sets first_response_at on the first reply.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: bool} previous status, new status, transition name, whether first response was set
     */
    private function applyReplyTransition(Ticket $ticket, int $ticketId): array
    {
        $currentStatus = $ticket->getStatus();
        $shouldSetFirstResponse = empty($ticket->getFirstResponseAt());
        $newStatus = $currentStatus;
        $transition = null;

        if ($currentStatus === TicketStatus::NEW->value) {
            // First agent response: new → active
            $newStatus = TicketStatus::ACTIVE->value;
            $transition = 'new_to_active';
        } elseif ($currentStatus === TicketStatus::ACTIVE->value) {
            // Agent waiting for response: active → waiting
            $newStatus = TicketStatus::WAITING->value;
            $transition = 'active_to_waiting';
        } elseif ($currentStatus === TicketStatus::WAITING->value) {
            // Agent replies while waiting: stays waiting (customer might respond)
            $newStatus = TicketStatus::WAITING->value;
            $transition = 'waiting_remains';
        }

        // Execute transition if needed
        if ($transition && $newStatus !== $currentStatus) {
            $this->transitionUseCase->execute(
                $ticketId,
                $newStatus,
                $shouldSetFirstResponse,
                false
            );
        } else if ($shouldSetFirstResponse) {
            // Just set first_response_at without status change
            $this->ticketRepo->markFirstResponse($ticketId);
        }

        return [$currentStatus, $newStatus, $transition, $shouldSetFirstResponse];
    }

    /**
     * Collects MessageCreatedEvent for human-agent replies (design.md D5), so
     * outbound messages are broadcast over Mercure. The message must already
     * be flushed, because the event carries its id.
     */
    private function collectMessageCreatedEvent(Ticket $ticket, int $ticketId, Message $msg, string $content, int $agentId): void
    {
        $senderName = $agentId > 0 ? $this->agentRepo->findById($agentId)?->getName() : null;
        $this->eventCollector->collect(new MessageCreatedEvent(
            eventId: new Ulid(),
            ticketId: $ticketId,
            occurredAt: new \DateTimeImmutable(),
            seq: $ticket->incrementLastEventSeq(),
            version: 1,
            direction: MessageDirection::OUTBOUND,
            messageId: $msg->getId(),
            content: $content,
            senderName: $senderName,
            media: $msg->getMedia(),
        ));
    }

    /**
     * Persist collected domain events to outbox for Mercure real-time.
     * After auto-assignment, events like TicketAssigned need to be published.
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

            if ($event instanceof TicketUpdatedEvent) {
                $payload['status'] = $event->status;
                if ($event->ownerAgentId !== null) {
                    $payload['assignee_id'] = $event->ownerAgentId;
                    // Fetch agent name for the frontend
                    $agent = $this->agentRepo->findById($event->ownerAgentId);
                    if ($agent !== null) {
                        $payload['assignee_name'] = $agent->getName();
                    }
                }
            } elseif ($event instanceof InboxUpdatedEvent) {
                // Skip inbox events for now (they don't have a wire format yet)
                continue;
            } elseif ($event instanceof MessageCreatedEvent) {
                $payload['message_id'] = $event->messageId;
                $payload['direction'] = $event->direction->value;
                $payload['content'] = $event->content;
                $payload['sender_name'] = $event->senderName;
                $payload['media'] = $event->media;
                // Include phone_number_id for workspace-pending indicator
                $phoneNumberId = $this->ticketRepo->findPhoneNumberId($event->ticketId);
                if (!empty($phoneNumberId)) {
                    $payload['phone_number_id'] = $phoneNumberId;
                }
            }

            $outboxEvent = new OutboxEvent(
                aggregateType: 'ticket',
                aggregateId: (int) $event->ticketId,
                topic: 'tickets',
                eventType: $event->type,
                payload: $payload
            );
            $outboxEvents[] = $outboxEvent;
        }

        $this->outboxWriter->write(...$outboxEvents);
        $this->eventCollector->clear();
    }
}