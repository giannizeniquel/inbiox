<?php

namespace App\Compartido\Realtime;

use App\Compartido\Outbox\EventoOutbox;

/**
 * Builds RealtimeEventContract v1 JSON arrays from EventoOutbox entities.
 *
 * Reads the stored snake_case payload and transforms it into the camelCase
 * wire format. The stored payload column remains unchanged — only the
 * output of build() is published to Mercure.
 */
final readonly class EventoEnTiempoRealFactory
{
    public function __construct(private TipoDeEventoMapper $mapper) {}

    /**
     * Build a RealtimeEventContract v1 array from an EventoOutbox.
     *
     * @return array{eventId: string, sequence: int, timestamp: string, eventType: string, ticketId: string, payload: array}
     */
    public function build(EventoOutbox $event): array
    {
        $stored   = $event->getPayload();
        $phpClass = $event->getTipoDeEvento();
        $enumValue = $this->resolveEnum($phpClass, $stored);

        return [
            'eventId'   => (string) ($stored['event_id'] ?? $event->getId()),
            'sequence'  => $event->getSequence(),
            'timestamp' => $stored['occurred_at'] ?? $event->getCreadoAt()->format(\DateTimeInterface::RFC3339),
            'eventType' => $enumValue->value,
            'ticketId'  => (string) $event->getAggregateId(),
            'payload'   => $this->buildPayload($enumValue, $stored, (int) $event->getAggregateId()),
        ];
    }

    /**
     * Resolve the PHP FQCN + payload to the correct TipoDeEventoEnTiempoReal.
     * TicketUpdatedEvent with assignee_id maps to TicketAssigned.
     */
    private function resolveEnum(string $phpClass, array $stored): TipoDeEventoEnTiempoReal
    {
        if ($this->mapper->isTicketAsignado($phpClass, $stored)) {
            return TipoDeEventoEnTiempoReal::TICKET_ASIGNADO;
        }
        // Handle wire-format event types (already enum values, not FQCNs)
        if (str_starts_with($phpClass, 'ticket.') || str_starts_with($phpClass, 'message.') || str_starts_with($phpClass, 'agent.') || str_starts_with($phpClass, 'conversation.')) {
            return TipoDeEventoEnTiempoReal::from($phpClass);
        }
        return TipoDeEventoEnTiempoReal::from($this->mapper->toEnum($phpClass));
    }

    /**
     * Build the typed payload section per event type.
     */
    private function buildPayload(TipoDeEventoEnTiempoReal $type, array $s, int $ticketId): array
    {
        return match ($type) {
            TipoDeEventoEnTiempoReal::TICKET_CREADO => [
                'ticketId'       => $ticketId,
                'contactId'      => (int) ($s['contact_id'] ?? 0),
                'conversationId' => (int) ($s['conversation_id'] ?? 0),
                'status'         => $s['status'] ?? '',
                'priority'       => (int) ($s['priority'] ?? 0),
                'createdAt'      => $s['created_at'] ?? $s['occurred_at'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::MENSAJE_AGREGADO => [
                'messageId'  => (int) ($s['message_id'] ?? 0),
                'ticketId'   => $ticketId,
                'content'    => $s['content'] ?? '',
                'direction'  => $s['direction'] ?? '',
                'senderName' => $s['sender_name'] ?? null,
                'createdAt'  => $s['created_at'] ?? $s['occurred_at'] ?? '',
                'externalId' => $s['external_id'] ?? null,
                'phoneNumberId' => $s['phone_number_id'] ?? null,
                'media'      => $s['media'] ?? null,
            ],
            TipoDeEventoEnTiempoReal::ESTADO_DE_TICKET_CAMBIADO => [
                'ticketId'       => $ticketId,
                'previousStatus' => $s['previous_status'] ?? null,
                'newStatus'      => $s['status'] ?? '',
                'changedAt'      => $s['changed_at'] ?? $s['occurred_at'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::TICKET_ASIGNADO => [
                'ticketId'        => $ticketId,
                'assigneeId'      => (int) ($s['assignee_id'] ?? $s['ownerAgentId'] ?? 0),
                'assigneeName'    => $s['assignee_name'] ?? null,
                'assignmentType'  => $s['assignment_type'] ?? 'MANUAL_ASSIGN',
                'assignedAt'      => $s['assigned_at'] ?? $s['occurred_at'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::DISPONIBILIDAD_DE_AGENTE_CAMBIADA => [
                'agent_id'        => (int) ($s['agent_id'] ?? 0),
                'is_available'    => (bool) ($s['is_available'] ?? false),
                'override_active' => (bool) ($s['override_active'] ?? false),
                'current_load'    => (int) ($s['current_load'] ?? 0),
                'max_capacity'    => (int) ($s['max_capacity'] ?? 0),
                'timestamp'       => $s['timestamp'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::TURNO_DE_AGENTE_CAMBIADO => [
                'agent_id'    => (int) ($s['agent_id'] ?? 0),
                'shift_id'    => (int) ($s['shift_id'] ?? 0),
                'action'      => $s['action'] ?? '',
                'day_of_week' => (int) ($s['day_of_week'] ?? 0),
                'start_time'  => $s['start_time'] ?? '',
                'end_time'    => $s['end_time'] ?? '',
                'timestamp'   => $s['timestamp'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::MENSAJE_ACTUALIZADO => [
                'messageId'    => (int) ($s['message_id'] ?? 0),
                'ticketId'     => $ticketId,
                'status'       => $s['delivery_status'] ?? null,
                'failedReason' => $s['failed_reason'] ?? null,
                'updatedAt'    => $s['occurred_at'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::TICKET_LEIDO => [
                'ticketId' => $ticketId,
            ],
        };
    }
}
