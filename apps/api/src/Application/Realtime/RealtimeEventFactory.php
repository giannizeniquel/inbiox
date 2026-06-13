<?php

namespace App\Application\Realtime;

use App\Domain\Outbox\OutboxEvent;

/**
 * Builds RealtimeEventContract v1 JSON arrays from OutboxEvent entities.
 *
 * Reads the stored snake_case payload and transforms it into the camelCase
 * wire format. The stored payload column remains unchanged — only the
 * output of build() is published to Mercure.
 */
final readonly class RealtimeEventFactory
{
    public function __construct(private EventTypeMapper $mapper) {}

    /**
     * Build a RealtimeEventContract v1 array from an OutboxEvent.
     *
     * @return array{eventId: string, sequence: int, timestamp: string, eventType: string, ticketId: string, payload: array}
     */
    public function build(OutboxEvent $event): array
    {
        $stored   = $event->getPayload();
        $phpClass = $event->getEventType();
        $enumValue = $this->resolveEnum($phpClass, $stored);

        return [
            'eventId'   => (string) ($stored['event_id'] ?? $event->getId()),
            'sequence'  => $event->getSequence(),
            'timestamp' => $stored['occurred_at'] ?? $event->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            'eventType' => $enumValue->value,
            'ticketId'  => (string) $event->getAggregateId(),
            'payload'   => $this->buildPayload($enumValue, $stored, (int) $event->getAggregateId()),
        ];
    }

    /**
     * Resolve the PHP FQCN + payload to the correct RealtimeEventType.
     * TicketUpdatedEvent with assignee_id maps to TicketAssigned.
     */
    private function resolveEnum(string $phpClass, array $stored): RealtimeEventType
    {
        if ($this->mapper->isTicketAssigned($phpClass, $stored)) {
            return RealtimeEventType::TicketAssigned;
        }
        return RealtimeEventType::from($this->mapper->toEnum($phpClass));
    }

    /**
     * Build the typed payload section per event type.
     */
    private function buildPayload(RealtimeEventType $type, array $s, int $ticketId): array
    {
        return match ($type) {
            RealtimeEventType::TicketCreated => [
                'ticketId'       => $ticketId,
                'contactId'      => (int) ($s['contact_id'] ?? 0),
                'conversationId' => (int) ($s['conversation_id'] ?? 0),
                'status'         => $s['status'] ?? '',
                'priority'       => (int) ($s['priority'] ?? 0),
                'createdAt'      => $s['created_at'] ?? $s['occurred_at'] ?? '',
            ],
            RealtimeEventType::MessageAdded => [
                'messageId'  => (int) ($s['message_id'] ?? 0),
                'ticketId'   => $ticketId,
                'content'    => $s['content'] ?? '',
                'direction'  => $s['direction'] ?? '',
                'senderName' => $s['sender_name'] ?? null,
                'createdAt'  => $s['created_at'] ?? $s['occurred_at'] ?? '',
                'externalId' => $s['external_id'] ?? null,
            ],
            RealtimeEventType::TicketStatusChanged => [
                'ticketId'       => $ticketId,
                'previousStatus' => $s['previous_status'] ?? null,
                'newStatus'      => $s['status'] ?? '',
                'changedAt'      => $s['changed_at'] ?? $s['occurred_at'] ?? '',
            ],
            RealtimeEventType::TicketAssigned => [
                'ticketId'   => $ticketId,
                'assigneeId' => (int) ($s['assignee_id'] ?? 0),
                'assignedAt' => $s['assigned_at'] ?? $s['occurred_at'] ?? '',
            ],
            RealtimeEventType::MessageUpdated => [
                'messageId'    => (int) ($s['message_id'] ?? 0),
                'ticketId'     => $ticketId,
                'status'       => $s['delivery_status'] ?? null,
                'failedReason' => $s['failed_reason'] ?? null,
                'updatedAt'    => $s['occurred_at'] ?? '',
            ],
            RealtimeEventType::ConversationUpdated => [
                'conversationId' => (int) ($s['conversation_id'] ?? 0),
                'ticketId'       => $ticketId,
                'lastMessageAt'  => $s['last_message_at'] ?? null,
            ],
        };
    }
}
