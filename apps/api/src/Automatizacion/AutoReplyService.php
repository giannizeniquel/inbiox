<?php

declare(strict_types=1);

namespace App\Automatizacion;

use App\Disponibilidad\AgentAvailabilityService;
use App\Compartido\Graph\GraphClient;

use App\Agente\Agent;
use App\Conversacion\Conversation;
use App\Compartido\EventCollector;
use App\Conversacion\MessageCreatedEvent;
use App\Conversacion\Message;
use App\Conversacion\MessageDirection;
use App\Conversacion\MessageSenderType;
use App\Conversacion\ConversationWindowPolicy;
use App\Compartido\ProcessedEvent;
use App\Ticket\Ticket;
use App\Compartido\Graph\GraphApiException;
use App\Agente\Repository\AgentRepository;
use App\Compartido\Repository\ProcessedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Fire-and-forget service to send auto-reply messages when conditions are met.
 *
 * Conditions (all must pass):
 * 1. Target agent has auto_reply_enabled = true
 * 2. Target agent has a non-empty auto_reply_message
 * 3. Target agent is NOT currently available (unavailable/off-shift)
 * 4. No recent auto-reply was sent to this contact (4h rate limit)
 *
 * If any condition fails, the method returns silently (fire-and-forget).
 * Exceptions from GraphClient are caught and logged — never propagated.
 */
final class AutoReplyService
{
    private const AUTO_REPLY_WINDOW_HOURS = 2;

    public function __construct(
        private readonly AgentRepository $agentRepo,
        private readonly AgentAvailabilityService $availabilityService,
        private readonly GraphClient $graphClient,
        private readonly ProcessedEventRepository $processedEventRepo,
        private readonly EventCollector $eventCollector,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly ConversationWindowPolicy $windowPolicy,
    ) {}

    /**
     * Attempt to send an auto-reply for the given ticket.
     *
     * This is a fire-and-forget method: it catches all exceptions and logs them,
     * never re-throwing. Called AFTER the inbound message transaction commits.
     */
    public function maybeSend(Ticket $ticket): void
    {
        try {
            $this->doMaybeSend($ticket);
        } catch (\Throwable $e) {
            $this->logger->warning('AutoReplyService::maybeSend unexpected error: {message}', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticket->getId(),
                'exception' => $e,
            ]);
        }
    }

    private function doMaybeSend(Ticket $ticket): void
    {
        // 1. Resolve target agent
        $agent = $this->resolveTargetAgent($ticket);
        if ($agent === null) {
            return; // No eligible agent
        }

        // 2. Check auto-reply is enabled and has a message
        if (!$agent->isAutoReplyEnabled()) {
            return;
        }

        $autoReplyMessage = $agent->getAutoReplyMessage();
        if ($autoReplyMessage === null || \trim($autoReplyMessage) === '') {
            return;
        }

        // 3. Check agent is NOT available (we send when they're unavailable)
        $now = new \DateTimeImmutable('now');
        if ($this->availabilityService->isAvailable($agent, $now)) {
            return; // Agent is available — don't send auto-reply
        }

        // 4. Rate limit check
        $contact = $ticket->getContacto();
        if ($contact === null) {
            return;
        }

        $contactId = (int) $contact->getId();
        $rateLimitKey = \sprintf('auto-reply:%d', $contactId);

        if ($this->hasRecentAutoReply($rateLimitKey)) {
            $this->logger->info('AutoReply rate-limited for contact {contactId}', [
                'contactId' => $contactId,
                'ticket_id' => $ticket->getId(),
            ]);
            return;
        }

        // 5. Send the message
        $phoneNumberId = $ticket->getPhoneNumberId();
        $to = $contact->getPhone();

        if ($to === null || $to === '') {
            $this->logger->warning('AutoReply skipped: contact has no phone number', [
                'contactId' => $contactId,
                'ticket_id' => $ticket->getId(),
            ]);
            return;
        }

        // WhatsApp 24h conversation-window guard: an auto-reply is fire-and-
        // forget, so an expired window is not an error — just an early
        // return with a log line, never a throw (design.md caller table).
        if (!$this->windowPolicy->isOpenForSending($ticket->getUltimoMensajeEntranteAt(), new \DateTimeImmutable())) {
            $this->logger->info('AutoReply skipped: conversation window has expired', [
                'contactId' => $contactId,
                'ticket_id' => $ticket->getId(),
            ]);
            return;
        }

        try {
            $graphResponse = $this->graphClient->sendMessage($to, $autoReplyMessage, $phoneNumberId);
        } catch (GraphApiException $e) {
            $this->logger->error('AutoReply Graph API error: {message}', [
                'message' => $e->getMessage(),
                'graphCode' => $e->getGraphCode(),
                'contactId' => $contactId,
                'ticket_id' => $ticket->getId(),
            ]);
            return; // Fire-and-forget: swallow the exception
        }

        // 6. Create local Message entity so the auto-reply appears in the conversation
        $wamid = $graphResponse['messages'][0]['id'] ?? null;
        $conversation = $ticket->getConversacion();
        $msg = null;
        if ($conversation !== null) {
            $msg = new Message();
            $msg->setConversation($conversation);
            $msg->setDirection('outbound');
            $msg->setContent($autoReplyMessage);
            if ($wamid !== null) {
                $msg->setExternalId($wamid);
            }
            $msg->setDeliveryStatus('sent');
            $msg->setSenderAgentId($agent->getId());
            $msg->setSenderType(MessageSenderType::AUTOMATIC);
            $this->em->persist($msg);

            // Update conversation timestamp
            $conversation->setLastMessageAt($msg->getCreatedAt());
            $conversation->setUpdatedAt(new \DateTimeImmutable());
        }

        // 7. Upsert rate-limit key (update timestamp or insert new)
        $existingEvent = $this->processedEventRepo->findByEventId($rateLimitKey);
        if ($existingEvent !== null) {
            $existingEvent->setProcessedAt(new \DateTimeImmutable('now'));
        } else {
            $processedEvent = new ProcessedEvent($rateLimitKey);
            $this->em->persist($processedEvent);
        }
        $this->em->flush();

        // 8. Emit outbox event so the message appears in real-time via Mercure
        if ($msg !== null) {
            $this->eventCollector->collect(new MessageCreatedEvent(
                eventId: new Ulid(),
                ticketId: $ticket->getId(),
                occurredAt: new \DateTimeImmutable(),
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
                direction: MessageDirection::OUTBOUND,
                messageId: (int) $msg->getId(),
                content: $autoReplyMessage,
                senderName: $agent->getName(),
            ));
        }

        $this->logger->info('AutoReply sent to contact {contactId}', [
            'contactId' => $contactId,
            'ticket_id' => $ticket->getId(),
        ]);
    }

    /**
     * Resolve which agent should send the auto-reply.
     *
     * Priority:
     * 1. Assigned agent (if set)
     * 2. First active agent in the workspace matching the ticket's phoneNumberId
     *    with auto_reply_enabled = true
     */
    private function resolveTargetAgent(Ticket $ticket): ?Agent
    {
        $assignedAgentId = $ticket->getAgenteAsignadoId();

        if ($assignedAgentId !== null) {
            $agent = $this->agentRepo->findById($assignedAgentId);
            if ($agent !== null && $agent->isActive()) {
                return $agent;
            }
        }

        // Fallback: find first active agent with auto_reply_enabled
        // Use the ticket's phoneNumberId to scope to the right workspace
        $phoneNumberId = $ticket->getPhoneNumberId();
        $allActive = $this->agentRepo->findAllActive();
        foreach ($allActive as $candidate) {
            if ($candidate->isAutoReplyEnabled()) {
                // Check if this agent is in a workspace matching the ticket's phoneNumberId
                if ($phoneNumberId !== null) {
                    foreach ($candidate->getWorkspaces() as $ws) {
                        if ($ws->getPhoneNumberId() === $phoneNumberId) {
                            return $candidate;
                        }
                    }
                } else {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Check if a recent auto-reply was sent for the given rate-limit key.
     */
    private function hasRecentAutoReply(string $rateLimitKey): bool
    {
        $existing = $this->processedEventRepo->findByEventId($rateLimitKey);
        if ($existing === null) {
            return false;
        }

        $windowEnd = $existing->getProcessedAt()->modify(
            \sprintf('+%d hours', self::AUTO_REPLY_WINDOW_HOURS)
        );

        return $windowEnd >= new \DateTimeImmutable('now');
    }
}
