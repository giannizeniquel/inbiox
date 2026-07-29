<?php

namespace App\Application\UseCase;

use App\Application\Service\AssignmentService;
use App\Domain\Assignment\AssignmentType;
use App\Domain\Conversation\Conversation;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Message\Message;
use App\Domain\Ticket\ProcessedEvent;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Application\Rule\RuleExecutorService;
use App\Domain\Rule\RuleContext;
use App\Exception\ConcurrencyException;
use App\Repository\AgentRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\ProcessedEventRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

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
    ) {}

    /**
     * Process outbound message from agent
     * Implements idempotency: duplicate messages (same ticket + content) are skipped.
     * Implements optimistic locking retry with exponential backoff.
     *
     * @param int $ticketId
     * @param string $content
     * @param int $agentId
     * @return array
     */
    public function processOutboundMessage(
        int $ticketId,
        string $content,
        int $agentId
    ): array {
        // Generate deterministic event key: outbound:{ticketId}:{contentHash}
        $contentHash = md5($content);
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
                return $this->executeTransactionWithRetry($ticketId, $content, $eventKey, $agentId, $ticket);
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
        ?Ticket &$ticket
    ): array {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $content, $eventKey, $agentId, &$ticket) {
            // Get ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
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
                }
            }

            // Create outbound message
            $msg = new Message();
            $msg->setConversation($conversation);
            $msg->setDirection('outbound');
            $msg->setContent($content);

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
}