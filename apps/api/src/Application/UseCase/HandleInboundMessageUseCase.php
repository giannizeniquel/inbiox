<?php

namespace App\Application\UseCase;

use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Event\EventCollector;
use App\Domain\Event\MessageCreatedEvent;
use App\Domain\Event\TicketCreatedEvent;
use App\Domain\Message\Message;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Repository\ContactRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use App\Application\Rule\RuleExecutorService;
use App\Domain\Rule\RuleContext;
use App\Application\UseCase\TransitionTicketStatusUseCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use App\Application\Event\TicketNeedsAutoAssignmentEvent;
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
    ) {}

    /**
     * Process an incoming message from a webhook or API call.
     *
     * @param string      $phone           Sender's phone number in E.164 format
     * @param string      $message         Message content
     * @param string|null $externalId      WAMID from Meta (for idempotency)
     * @param string|null $phoneNumberId   WhatsApp phone number ID that received this message (CAP-7)
     * @param string|null $contactName     Optional contact name to set on new/existing contacts
     *
     * @return array{contact_id: int, conversation_id: int, message_id: int, ticket_id: int|null, status: string}
     */
    public function processInboundMessage(string $phone, string $message, ?string $externalId = null, ?string $phoneNumberId = null, ?string $contactName = null): array
    {
        // 1. Normalize phone: strip non-digits, then strip mobile prefix 9 if present (Argentina format)
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
        // Normalize Argentine mobile numbers: 549 → 54 (strip the 9)
        if (str_starts_with($normalizedPhone, '549')) {
            $normalizedPhone = '54' . substr($normalizedPhone, 3);
        }
        if (strlen($normalizedPhone) < 10) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        $normalizedPhone = '+' . $normalizedPhone;

        $result = $this->em->getConnection()->transactional(function() use ($normalizedPhone, $message, $externalId, $phoneNumberId, $contactName) {
            return $this->executeInternal($normalizedPhone, $message, $externalId, $phoneNumberId, $contactName);
        });

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

    private function executeInternal(string $phone, string $message, ?string $externalId, ?string $phoneNumberId = null, ?string $contactName = null): array
    {
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
        $conversation = $this->conversationRepo->findOpenByContact($contact);
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

        $this->em->persist($msg);

        // 6. Handle Ticket - create if new conversation or no open ticket
        // Use findNotClosedByContact to include resolved tickets (for auto-reopen)
        $ticket = $this->ticketRepo->findNotClosedByContact($contact);
        $isNewTicket = false;
        
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
            direction: \App\Domain\Message\MessageDirection::INBOUND,
            messageId: $msg->getId(),
            content: $message,
            senderName: $contact->getName(),
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
