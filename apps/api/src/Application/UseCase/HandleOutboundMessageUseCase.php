<?php

namespace App\Application\UseCase;

use App\Application\Service\AssignmentService;
use App\Domain\Assignment\AssignmentType;
use App\Domain\Conversation\Conversation;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Event\EventCollector;
use App\Domain\Event\InboxUpdatedEvent;
use App\Domain\Event\TicketUpdatedEvent;
use App\Domain\Message\Message;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Ticket\ProcessedEvent;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Application\Rule\RuleExecutorService;
use App\Domain\Rule\RuleContext;
use App\Domain\Ticket\ConversationWindowPolicy;
use App\Exception\ConcurrencyException;
use App\Exception\ConversationWindowExpiredException;
use App\Repository\AgentRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\ProcessedEventRepository;
use App\Repository\TicketRepository;
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

        while ($attempt < $maxRetries) {
            try {
                $result = $this->executeTransactionWithRetry($ticketId, $content, $eventKey, $agentId, $ticket, $mediaMetadata);
                $this->persistEventsToOutbox();
                return $result;
            } catch (OptimisticLockException $e) {
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
            }
        }

        // Should never reach here, but satisfy static analysis
        throw new ConcurrencyException('Unexpected error in retry loop');
    }

    /**
     * Execute the transactional operation with ticket reference for refresh.
     */
    private function executeTransactionWithRetry(
        int $ticketId,
        string $content,
        string $eventKey,
        int $agentId,
        ?Ticket &$ticket,
        ?array $mediaMetadata = null,
    ): array {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $content, $eventKey, $agentId, &$ticket, $mediaMetadata) {
            // Get ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
            }

            // WhatsApp 24h conversation-window guard-before-persist (design.md
            // D3/D4): reject free text once the effective window has elapsed,
            // before any message row is persisted and before any FSM
            // transition — never a phantom "sent" message.
            if (!$this->windowPolicy->isOpenForSending($ticket->getLastInboundMessageAt(), new \DateTimeImmutable())) {
                throw new ConversationWindowExpiredException($ticketId, $ticket->getLastInboundMessageAt());
            }

            // Get conversation
            $conversation = $ticket->getConversation();
            if (!$conversation) {
                throw new \InvalidArgumentException('Ticket has no conversation');
            }

            // Auto-assign agent to ticket if not already assigned to this agent
            $currentAssigneeId = $ticket->getAssignedAgentId();
            if ($agentId > 0 && $currentAssigneeId !== $agentId) {
                $agent = $this->agentRepo->findById($agentId);
                if ($agent !== null) {
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
            }

            // Create outbound message
            $msg = new Message();
            $msg->setConversation($conversation);
            $msg->setDirection('outbound');
            $msg->setContent($content);

            // Attach media metadata if provided
            if ($mediaMetadata !== null) {
                $msg->setMedia($mediaMetadata);
            }

            $this->em->persist($msg);

            // Determine transition based on current status
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
                $result = $this->transitionUseCase->execute(
                    $ticketId,
                    $newStatus,
                    $shouldSetFirstResponse,
                    false
                );
            } else if ($shouldSetFirstResponse) {
                // Just set first_response_at without status change
                $this->em->getConnection()->executeStatement(
                    "UPDATE tickets SET first_response_at = NOW(), updated_at = NOW() WHERE id = ?",
                    [$ticketId]
                );
            }

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
     * Persist collected domain events to outbox for Mercure real-time.
     * After auto-assignment, events like TicketAssigned need to be published.
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
            }

            $outboxEvent = new OutboxEvent(
                aggregateType: 'ticket',
                aggregateId: (int) $event->ticketId,
                topic: 'tickets',
                eventType: $event->type,
                payload: $payload
            );
            $this->em->persist($outboxEvent);
        }

        $this->em->flush();
        $this->eventCollector->clear();
    }
}