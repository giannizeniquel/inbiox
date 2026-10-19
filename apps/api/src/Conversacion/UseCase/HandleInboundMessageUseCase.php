<?php

namespace App\Conversacion\UseCase;

use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Conversacion\ConversationStatus;
use App\Compartido\EventCollector;
use App\Conversacion\MessageCreatedEvent;
use App\Ticket\TicketCreatedEvent;
use App\Conversacion\Message;
use App\Conversacion\MessageSenderType;
use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Contacto\Repository\ContactoRepository;
use App\Conversacion\Repository\ConversationRepository;
use App\Conversacion\Repository\MessageRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use App\Automatizacion\EjecutorDeReglas;
use App\Automatizacion\RespuestaAutomaticaService;
use App\Automatizacion\ContextoDeRegla;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use App\Conversacion\MediaDownloadService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use App\Asignacion\TicketNeedsAutoAssignmentEvent;
use Symfony\Component\Uid\Ulid;

class HandleInboundMessageUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactoRepository $contactRepo,
        private ConversationRepository $conversationRepo,
        private MessageRepository $messageRepo,
        private TicketRepository $ticketRepo,
        private EventDispatcherInterface $eventDispatcher,
        private CambiarEstadoDeTicketUseCase $transitionUseCase,
        private EjecutorDeReglas $ruleExecutor,
        private EventCollector $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private RespuestaAutomaticaService $autoReplyService,
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
            $outboxEvents[] = $outboxEvent;
        }

        $this->outboxWriter->write(...$outboxEvents);
        $this->eventCollector->clear();
    }

    /**
     * Runs inside the inbound transaction. The steps keep their original
     * order: three flushes (message, media, everything else), the media
     * download from Graph before the ticket lock, and the event sequence
     * increments after the last flush.
     */
    private function executeInternal(
        string $phone,
        string $message,
        ?string $externalId,
        ?string $phoneNumberId = null,
        ?string $contactName = null,
        ?array $mediaDownloadPayload = null,
    ): array {
        $contact = $this->findOrCreateContact($phone, $contactName);
        $conversation = $this->findOrCreateOpenConversation($contact, $phoneNumberId);

        // Idempotency by WAMID. It runs after the contact and conversation
        // may have been created, so a duplicate still stores those.
        $existingMessage = $this->findAlreadyStoredMessage($externalId);
        if ($existingMessage) {
            return [
                'contact_id' => $contact->getId(),
                'conversation_id' => $conversation->getId(),
                'message_id' => $existingMessage->getId(),
                'ticket_id' => $this->getTicketForConversation($conversation)?->getId(),
                'status' => 'ok'
            ];
        }

        $msg = $this->storeInboundMessage($conversation, $message, $externalId);
        $this->downloadMediaInsideTransaction($msg, $mediaDownloadPayload);

        [$ticket, $isNewTicket] = $this->findOrReopenOrCreateTicketAndLock($contact, $conversation, $phoneNumberId);
        $transition = $this->moveWaitingTicketToActive($ticket);

        $this->touchTimestamps($conversation, $contact, $ticket, $msg);
        $this->em->flush();

        $this->collectInboundEvents($ticket, $isNewTicket, $msg, $message, $contact);
        $this->runRulesAndRequestAutoAssignment($ticket, $msg);

        return [
            'contact_id' => $contact->getId(),
            'conversation_id' => $conversation->getId(),
            'message_id' => $msg->getId(),
            'ticket_id' => $ticket->getId(),
            'ticket_status' => $ticket->getEstado(),
            'transition' => $transition,
            'status' => 'ok'
        ];
    }

    private function findOrCreateContact(string $phone, ?string $contactName): Contacto
    {
        $contact = $this->contactRepo->findOneByTelefono($phone);
        if (!$contact) {
            $contact = new Contacto();
            $contact->setTelefono($phone);
            if ($contactName !== null) {
                $contact->setNombre($contactName);
            }
            $this->em->persist($contact);
        } elseif ($contactName !== null && $contact->getNombre() === null) {
            // Update name on existing contact if it was missing
            $contact->setNombre($contactName);
        }

        return $contact;
    }

    private function findOrCreateOpenConversation(Contacto $contact, ?string $phoneNumberId): Conversation
    {
        $conversation = $this->conversationRepo->findOpenByContact($contact, $phoneNumberId);
        if (!$conversation) {
            $conversation = new Conversation();
            $conversation->setContact($contact);
            $conversation->setStatus(ConversationStatus::OPEN->value);
            if ($phoneNumberId !== null) {
                $conversation->setPhoneNumberId($phoneNumberId);
            }
            $this->em->persist($conversation);
        }

        return $conversation;
    }

    private function findAlreadyStoredMessage(?string $externalId): ?Message
    {
        if (!$externalId) {
            return null;
        }

        return $this->messageRepo->findByExternalId($externalId);
    }

    /** Persists the message and flushes (first flush), so it has an id for the media key. */
    private function storeInboundMessage(Conversation $conversation, string $message, ?string $externalId): Message
    {
        $msg = new Message();
        $msg->setConversation($conversation);
        $msg->setDirection('inbound');
        $msg->setContent($message);
        if ($externalId) {
            $msg->setExternalId($externalId);
        }
        $msg->setSenderType(MessageSenderType::CONTACT);

        $this->em->persist($msg);
        $this->em->flush();

        return $msg;
    }

    /**
     * Downloads the media from Graph and stores it while the inbound
     * transaction is open (contradiction track, S10). A failure only logs:
     * the message stays stored without media. Success flushes (second flush).
     */
    private function downloadMediaInsideTransaction(Message $msg, ?array $mediaDownloadPayload): void
    {
        if (!$mediaDownloadPayload || $this->mediaDownloadService === null) {
            return;
        }

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

    /**
     * Uses the contact's not-closed ticket (resolved included, for the
     * auto-reopen), else reopens its window-expired closed ticket on the same
     * conversation, else creates a new one. An existing ticket is locked.
     *
     * @return array{0: Ticket, 1: bool} the ticket and whether it is new
     */
    private function findOrReopenOrCreateTicketAndLock(Contacto $contact, Conversation $conversation, ?string $phoneNumberId): array
    {
        $ticket = $this->ticketRepo->findNoCerradoByContacto($contact, $phoneNumberId);
        $wasReopened = false;

        // WhatsApp 24h conversation-window compliance: a ticket closed
        // specifically by the window-expiry process reactivates on a new
        // inbound message instead of spawning a new ticket (design.md D1).
        // No human-facing endpoint can trigger this — see
        // CambiarEstadoDeTicketUseCase::reopenDueToInboundMessage()'s own
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
            $reopenable = $this->ticketRepo->findCerradoPorVentanaVencidaByContacto($contact, $phoneNumberId);
            if ($reopenable !== null
                && $reopenable->getConversacion()?->getId() === $conversation->getId()) {
                $this->transitionUseCase->reabrirPorMensajeEntrante((int) $reopenable->getId());
                $ticket = $reopenable;
                $wasReopened = true;
            }
        }

        if (!$ticket) {
            $ticket = new Ticket();
            $ticket->setContacto($contact);
            $ticket->setConversacion($conversation);
            $ticket->setEstado(EstadoDeTicket::NUEVO->value);
            $ticket->setPrioridad('medium');
            if ($phoneNumberId !== null) {
                $ticket->setPhoneNumberId($phoneNumberId);
            }
            $this->em->persist($ticket);

            return [$ticket, true];
        }

        // Existing ticket: lock to prevent seq duplicates under concurrency
        $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);

        // `reopenDueToInboundMessage()` writes via raw SQL, so the
        // managed entity fetched above still reports the stale
        // `status = 'closed'`. Without this refresh, the waiting→active
        // step (and the returned `ticket_status`) would read/report the
        // wrong status.
        if ($wasReopened) {
            $this->em->refresh($ticket);
        }

        return [$ticket, false];
    }

    /**
     * FSM transition on a customer response. The transition writes with raw
     * SQL and the managed ticket keeps `waiting` (contradiction track, S10).
     */
    private function moveWaitingTicketToActive(Ticket $ticket): ?string
    {
        if ($ticket->getEstado() !== EstadoDeTicket::EN_ESPERA->value) {
            return null;
        }

        $this->transitionUseCase->execute(
            $ticket->getId(),
            EstadoDeTicket::ACTIVO->value,
            false, // don't set first_response - this is customer
            false  // don't set resolved
        );

        return 'waiting_to_active';
    }

    private function touchTimestamps(Conversation $conversation, Contacto $contact, Ticket $ticket, Message $msg): void
    {
        $conversation->setLastMessageAt($msg->getCreatedAt());
        $conversation->setUpdatedAt(new \DateTimeImmutable());
        $contact->setActualizadoAt(new \DateTimeImmutable());
        $ticket->setActualizadoAt(new \DateTimeImmutable());

        // WhatsApp 24h conversation-window compliance: this is the single
        // writer for `lastInboundMessageAt` (design.md, task 3.1/3.8) — it
        // must fire on every inbound message, for both a brand-new ticket
        // and a reused/reopened one, and on no other path (outbound sends
        // and auto-replies never touch this field).
        $ticket->setUltimoMensajeEntranteAt($msg->getCreatedAt());
    }

    /**
     * Collected after the last flush, so the ticket has its id. The sequence
     * increments are saved by the outbox writer's flush.
     */
    private function collectInboundEvents(Ticket $ticket, bool $isNewTicket, Message $msg, string $message, Contacto $contact): void
    {
        if ($isNewTicket) {
            $this->eventCollector->collect(new TicketCreatedEvent(
                eventId: new Ulid(),
                ticketId: $ticket->getId(),
                occurredAt: new \DateTimeImmutable(),
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
                status: EstadoDeTicket::NUEVO,
                priority: 1 // medium = 1
            ));
        }

        $this->eventCollector->collect(new MessageCreatedEvent(
            eventId: new Ulid(),
            ticketId: $ticket->getId(),
            occurredAt: new \DateTimeImmutable(),
            seq: $ticket->incrementLastEventSeq(),
            version: 1,
            direction: \App\Conversacion\MessageDirection::INBOUND,
            messageId: $msg->getId(),
            content: $message,
            senderName: $contact->getNombre(),
            media: $msg->getMedia(),
        ));
    }

    /**
     * Inbound automation rules, then the auto-assignment request. Its
     * listener runs synchronously, inside the inbound transaction.
     */
    private function runRulesAndRequestAutoAssignment(Ticket $ticket, Message $msg): void
    {
        $ruleContext = new ContextoDeRegla($ticket, $msg, 'inbound');
        $this->ruleExecutor->execute($ruleContext);

        if ($ticket->getId()) {
            $this->eventDispatcher->dispatch(new TicketNeedsAutoAssignmentEvent($ticket->getId()));
        }
    }

    private function getTicketForConversation(Conversation $conversation): ?Ticket
    {
        return $this->ticketRepo->findByConversacion($conversation);
    }
}
