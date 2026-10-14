<?php

namespace App\Conversacion\UseCase;

use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Conversacion\ConversationStatus;
use App\Compartido\EventCollector;
use App\Conversacion\MessageCreatedEvent;
use App\Ticket\TicketCreatedEvent;
use App\Conversacion\Message;
use App\Conversacion\MessageSenderType;
use App\Compartido\Outbox\OutboxEvent;
use App\Ticket\Ticket;
use App\Ticket\TicketStatus;
use App\Contacto\Repository\ContactRepository;
use App\Conversacion\Repository\ConversationRepository;
use App\Conversacion\Repository\MessageRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use App\Automatizacion\RuleExecutorService;
use App\Automatizacion\AutoReplyService;
use App\Automatizacion\RuleContext;
use App\Ticket\UseCase\TransitionTicketStatusUseCase;
use App\Conversacion\MediaDownloadService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use App\Asignacion\TicketNeedsAutoAssignmentEvent;
use Symfony\Component\Uid\Ulid;

class HandleInboundMessageUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactRepository $contactRepo,
        private ConversationRepository $conversationRepo,
        private MessageRepository $messageRepo,
        private TicketRepository $ticketRepo,
        private EventDispatcherInterface $eventDispatcher,
        private TransitionTicketStatusUseCase $transitionUseCase,
        private RuleExecutorService $ruleExecutor,
        private EventCollector $eventCollector,
        private AutoReplyService $autoReplyService,
        private LoggerInterface $logger,
        private readonly ?MediaDownloadService $mediaDownloadService = null,
    ) {}

    /**
     * Process an incoming message from a webhook or API call.
     *
     * @param string      $phone           Sender's phone number in E.164 format
     * @param string      $message         Message content
     * @param string|null $externalId      WAMID from Meta (for idempotency)
     * @param string|null $phoneNumberId   WhatsApp phone number ID that received this message (CAP-7)
     * @param string|null $contactName     Optional contact name to set on new/existing contacts
     * @param array|null  $mediaDownloadPayload  Optional media payload: {mediaId, mimeType, filename, caption}
     *
     * @return array{contact_id: int, conversation_id: int, message_id: int, ticket_id: int|null, status: string}
     */
    public function processInboundMessage(
        string $phone,
        string $message,
        ?string $externalId = null,
        ?string $phoneNumberId = null,
        ?string $contactName = null,
        ?array $mediaDownloadPayload = null,
    ): array {
        // 1. Normalize phone: strip non-digits, keep format as-is (WhatsApp sends correct prefix)
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($normalizedPhone) < 10) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        $normalizedPhone = '+' . $normalizedPhone;

        $result = $this->em->getConnection()->transactional(function() use ($normalizedPhone, $message, $externalId, $phoneNumberId, $contactName, $mediaDownloadPayload) {
            return $this->executeInternal($normalizedPhone, $message, $externalId, $phoneNumberId, $contactName, $mediaDownloadPayload);
        });

        // After commit, attempt auto-reply (fire-and-forget)
        // This runs BEFORE persistEventsToOutbox to keep ordering deterministic
        try {
            $ticketId = $result['ticket_id'] ?? null;
            if ($ticketId !== null) {
                $ticket = $this->ticketRepo->find($ticketId);
                if ($ticket !== null) {
                    $this->autoReplyService->maybeSend($ticket);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AutoReply skipped due to error: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        // After commit, persist collected events to outbox
        $this->persistEventsToOutbox();

        return $result;
    }

    /**
     * Persist collected domain events to outbox table
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

            // Add event-specific fields
            if ($event instanceof MessageCreatedEvent) {
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
            } elseif ($event instanceof TicketCreatedEvent) {
                $payload['status'] = $event->status->value;
                $payload['priority'] = $event->priority;
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

    private function executeInternal(
        string $phone,
        string $message,
        ?string $externalId,
        ?string $phoneNumberId = null,
        ?string $contactName = null,
        ?array $mediaDownloadPayload = null,
    ): array {
        // 2. Find or create Contact
        $contact = $this->contactRepo->findOneByPhone($phone);
        if (!$contact) {
            $contact = new Contact();
            $contact->setPhone($phone);
            if ($contactName !== null) {
                $contact->setName($contactName);
            }
            $this->em->persist($contact);
        } elseif ($contactName !== null && $contact->getName() === null) {
            // Update name on existing contact if it was missing
            $contact->setName($contactName);
        }

        // 3. Find or create Conversation
        $conversation = $this->conversationRepo->findOpenByContact($contact, $phoneNumberId);
        $isNewConversation = false;
        if (!$conversation) {
            $conversation = new Conversation();
            $conversation->setContact($contact);
            $conversation->setStatus(ConversationStatus::OPEN->value);
            if ($phoneNumberId !== null) {
                $conversation->setPhoneNumberId($phoneNumberId);
            }
            $this->em->persist($conversation);
            $isNewConversation = true;
        }

        // 4. Check idempotency - if externalId provided and exists, return existing
        if ($externalId) {
            $existingMessage = $this->messageRepo->findByExternalId($externalId);
            if ($existingMessage) {
                return [
                    'contact_id' => $contact->getId(),
                    'conversation_id' => $conversation->getId(),
                    'message_id' => $existingMessage->getId(),
                    'ticket_id' => $this->getTicketForConversation($conversation)?->getId(),
                    'status' => 'ok'
                ];
            }
        }

        // 5. Create Message
        $msg = new Message();
        $msg->setConversation($conversation);
        $msg->setDirection('inbound');
        $msg->setContent($message);
        if ($externalId) {
            $msg->setExternalId($externalId);
        }
        $msg->setSenderType(MessageSenderType::CONTACT);

        $this->em->persist($msg);
        $this->em->flush(); // Flush to get message ID for media key

        // 5b. Handle media download and storage (fire-and-forget on failure)
        if ($mediaDownloadPayload && $this->mediaDownloadService !== null) {
            try {
                $mediaId = $mediaDownloadPayload['mediaId'];
                $mimeType = $mediaDownloadPayload['mimeType'] ?? 'application/octet-stream';
                $filename = $mediaDownloadPayload['filename'] ?? 'file';
                $caption = $mediaDownloadPayload['caption'] ?? null;

                // Determine tenant and ticket for the object key
                $tenantId = 'default'; // Default tenant prefix
                $ticketId = $msg->getId(); // Use message ID as part of key

                $mediaResult = $this->mediaDownloadService->downloadAndStore(
                    mediaId: $mediaId,
                    mimeType: $mimeType,
                    tenantId: $tenantId,
                    ticketId: $ticketId,
                    filename: $filename,
                );

                if ($mediaResult !== null) {
                    $msg->setMedia([
                        'key' => $mediaResult['key'],
                        'mimeType' => $mimeType,
                        'filename' => $filename,
                        'size' => $mediaResult['size'],
                        'kind' => $mediaResult['kind'],
                        'providerId' => $mediaId,
                        'caption' => $caption,
                    ]);
                    $this->em->flush();
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to process inbound media', [
                    'error' => $e->getMessage(),
                    'mediaId' => $mediaDownloadPayload['mediaId'] ?? null,
                ]);
                // Continue without media — message is already persisted
            }
        }

        // 6. Handle Ticket - create if new conversation or no open ticket
        // Use findNotClosedByContact to include resolved tickets (for auto-reopen)
        $ticket = $this->ticketRepo->findNotClosedByContact($contact, $phoneNumberId);
        $isNewTicket = false;
        $wasReopened = false;

        // WhatsApp 24h conversation-window compliance: a ticket closed
        // specifically by the window-expiry process reactivates on a new
        // inbound message instead of spawning a new ticket (design.md D1).
        // No human-facing endpoint can trigger this — see
        // TransitionTicketStatusUseCase::reopenDueToInboundMessage()'s own
        // guard and TicketWorkflowControllerWindowIsolationTest.
        //
        // Known accepted limitation (proposal.md/design.md, deferred to a
        // future phase): a reopened ticket may not appear live in the
        // agent's ticket list, because no ticket-level Mercure event fires
        // for it — `TicketUpdatedEvent` is deliberately NOT extended here
        // (fixed constraint). The reopen is fully correct in the database
        // (status='active', closed_at=NULL, `ticket_events` audit row) and
        // becomes visible on the agent's next list load. Do NOT add a new
        // event emission here to close this gap — it is out of scope for
        // this change.
        if (!$ticket) {
            $reopenable = $this->ticketRepo->findWindowExpiredClosedByContact($contact, $phoneNumberId);
            if ($reopenable !== null
                && $reopenable->getConversation()?->getId() === $conversation->getId()) {
                $this->transitionUseCase->reopenDueToInboundMessage((int) $reopenable->getId());
                $ticket = $reopenable;
                $wasReopened = true;
            }
        }

        if (!$ticket) {
            $ticket = new Ticket();
            $ticket->setContact($contact);
            $ticket->setConversation($conversation);
            $ticket->setStatus(TicketStatus::NEW->value);
            $ticket->setPriority('medium');
            if ($phoneNumberId !== null) {
                $ticket->setPhoneNumberId($phoneNumberId);
            }
            $this->em->persist($ticket);
            $isNewTicket = true;
        } else {
            // Existing ticket: lock to prevent seq duplicates under concurrency
            $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);

            // `reopenDueToInboundMessage()` writes via raw SQL, so the
            // managed entity fetched above still reports the stale
            // `status = 'closed'`. Without this refresh, step 7's
            // waiting→active branch (and the returned `ticket_status`)
            // would read/report the wrong status.
            if ($wasReopened) {
                $this->em->refresh($ticket);
            }
        }

        // 7. FSM transition: waiting → active on customer response
        $currentStatus = $ticket->getStatus();
        $transition = null;
        
        if ($currentStatus === TicketStatus::WAITING->value) {
            // Customer responds while waiting → go back to active
            $result = $this->transitionUseCase->execute(
                $ticket->getId(),
                TicketStatus::ACTIVE->value,
                false, // don't set first_response - this is customer
                false  // don't set resolved
            );
            $transition = 'waiting_to_active';
        }

        // 8. Update conversation last_message_at
        $conversation->setLastMessageAt($msg->getCreatedAt());
        $conversation->setUpdatedAt(new \DateTimeImmutable());
        $contact->setUpdatedAt(new \DateTimeImmutable());
        $ticket->setUpdatedAt(new \DateTimeImmutable());

        // WhatsApp 24h conversation-window compliance: this is the single
        // writer for `lastInboundMessageAt` (design.md, task 3.1/3.8) — it
        // must fire on every inbound message, for both a brand-new ticket
        // and a reused/reopened one, and on no other path (outbound sends
        // and auto-replies never touch this field).
        $ticket->setLastInboundMessageAt($msg->getCreatedAt());

        // 8. Flush everything
        $this->em->flush();

        // Collect TicketCreatedEvent after flush (ticket now has a persisted ID)
        if ($isNewTicket) {
            $this->eventCollector->collect(new TicketCreatedEvent(
                eventId: new Ulid(),
                ticketId: $ticket->getId(), // CORRECT — now persisted
                occurredAt: new \DateTimeImmutable(),
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
                status: TicketStatus::NEW,
                priority: 1 // medium = 1
            ));
        }

        // Collect MessageCreatedEvent after message is persisted
        $this->eventCollector->collect(new MessageCreatedEvent(
            eventId: new Ulid(),
            ticketId: $ticket->getId(),
            occurredAt: new \DateTimeImmutable(),
            seq: $ticket->incrementLastEventSeq(),
            version: 1,
            direction: \App\Conversacion\MessageDirection::INBOUND,
            messageId: $msg->getId(),
            content: $message,
            senderName: $contact->getName(),
            media: $msg->getMedia(),
        ));

        // 9. Execute automation rules (inbound trigger)
        $ruleContext = new RuleContext($ticket, $msg, 'inbound');
        $this->ruleExecutor->execute($ruleContext);

        // 10. Dispatch event for auto-assignment (decoupled from this use case)
        if ($ticket->getId()) {
            $this->eventDispatcher->dispatch(new TicketNeedsAutoAssignmentEvent($ticket->getId()));
        }

        return [
            'contact_id' => $contact->getId(),
            'conversation_id' => $conversation->getId(),
            'message_id' => $msg->getId(),
            'ticket_id' => $ticket->getId(),
            'ticket_status' => $ticket->getStatus(),
            'transition' => $transition,
            'status' => 'ok'
        ];
    }

    private function getTicketForConversation(Conversation $conversation): ?Ticket
    {
        return $this->ticketRepo->findByConversation($conversation);
    }
}
