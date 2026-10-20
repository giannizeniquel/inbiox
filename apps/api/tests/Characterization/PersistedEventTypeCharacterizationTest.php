<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Compartido\DomainEvent;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Realtime\TipoDeEventoMapper;
use App\Compartido\Realtime\EventoEnTiempoRealFactory;
use App\Conversacion\MessageCreatedEvent;
use App\Conversacion\MessageUpdatedEvent;
use App\Disponibilidad\AgentAvailabilityChangedEvent;
use App\Disponibilidad\AgentShiftChangedEvent;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketCreatedEvent;
use App\Ticket\TicketUpdatedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the event class names stored in outbox_events.event_type and the
 * Mercure message built from a row with each stored name.
 *
 * The names are string literals on purpose: renaming one of these classes
 * would orphan pending and dead-letter rows that still carry the old name.
 */
final class PersistedEventTypeCharacterizationTest extends TestCase
{
    public function testEventClassesKeepTheirStoredNames(): void
    {
        self::assertSame('App\Ticket\TicketCreatedEvent', TicketCreatedEvent::class);
        self::assertSame('App\Ticket\TicketUpdatedEvent', TicketUpdatedEvent::class);
        self::assertSame('App\Conversacion\MessageCreatedEvent', MessageCreatedEvent::class);
        self::assertSame('App\Conversacion\MessageUpdatedEvent', MessageUpdatedEvent::class);
        self::assertSame('App\Disponibilidad\AgentAvailabilityChangedEvent', AgentAvailabilityChangedEvent::class);
        self::assertSame('App\Disponibilidad\AgentShiftChangedEvent', AgentShiftChangedEvent::class);
        self::assertSame('App\Ticket\InboxUpdatedEvent', InboxUpdatedEvent::class);
        self::assertSame('App\Compartido\DomainEvent', DomainEvent::class);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    public static function storedRows(): iterable
    {
        yield 'ticket created' => [
            'App\Ticket\TicketCreatedEvent',
            ['event_id' => 'evt-1', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'contact_id' => 7, 'conversation_id' => 8, 'status' => 'new', 'priority' => 2, 'created_at' => '2026-10-01T10:00:00+00:00'],
            ['eventType' => 'ticket.created', 'payload' => ['ticketId' => 100, 'contactId' => 7, 'conversationId' => 8, 'status' => 'new', 'priority' => 2, 'createdAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'ticket status changed' => [
            'App\Ticket\TicketUpdatedEvent',
            ['event_id' => 'evt-2', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'previous_status' => 'new', 'status' => 'active', 'changed_at' => '2026-10-01T10:00:00+00:00'],
            ['eventType' => 'ticket.status_changed', 'payload' => ['ticketId' => 100, 'previousStatus' => 'new', 'newStatus' => 'active', 'changedAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'ticket assigned' => [
            'App\Ticket\TicketUpdatedEvent',
            ['event_id' => 'evt-3', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'ownerAgentId' => 5, 'assignee_name' => 'Ana', 'assignment_type' => 'AUTO_ASSIGN', 'assigned_at' => '2026-10-01T10:00:00+00:00'],
            ['eventType' => 'ticket.assigned', 'payload' => ['ticketId' => 100, 'assigneeId' => 5, 'assigneeName' => 'Ana', 'assignmentType' => 'AUTO_ASSIGN', 'assignedAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'message added' => [
            'App\Conversacion\MessageCreatedEvent',
            ['event_id' => 'evt-4', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'message_id' => 9, 'content' => 'hola', 'direction' => 'inbound', 'sender_name' => 'Juan', 'created_at' => '2026-10-01T10:00:00+00:00', 'external_id' => 'wamid.1', 'phone_number_id' => '123', 'media' => null],
            ['eventType' => 'message.added', 'payload' => ['messageId' => 9, 'ticketId' => 100, 'content' => 'hola', 'direction' => 'inbound', 'senderName' => 'Juan', 'createdAt' => '2026-10-01T10:00:00+00:00', 'externalId' => 'wamid.1', 'phoneNumberId' => '123', 'media' => null]],
        ];
        yield 'message updated' => [
            'App\Conversacion\MessageUpdatedEvent',
            ['event_id' => 'evt-5', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'message_id' => 9, 'delivery_status' => 'failed', 'failed_reason' => 'expired'],
            ['eventType' => 'message.updated', 'payload' => ['messageId' => 9, 'ticketId' => 100, 'status' => 'failed', 'failedReason' => 'expired', 'updatedAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'agent availability changed' => [
            'App\Disponibilidad\AgentAvailabilityChangedEvent',
            ['event_id' => 'evt-6', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'agent_id' => 5, 'is_available' => true, 'override_active' => true, 'current_load' => 2, 'max_capacity' => 4, 'timestamp' => '2026-10-01T10:00:00+00:00'],
            ['eventType' => 'agent.availability_changed', 'payload' => ['agent_id' => 5, 'is_available' => true, 'override_active' => true, 'current_load' => 2, 'max_capacity' => 4, 'timestamp' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'agent shift changed' => [
            'App\Disponibilidad\AgentShiftChangedEvent',
            ['event_id' => 'evt-7', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'agent_id' => 5, 'shift_id' => 3, 'action' => 'created', 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'timestamp' => '2026-10-01T10:00:00+00:00'],
            ['eventType' => 'agent.shift_changed', 'payload' => ['agent_id' => 5, 'shift_id' => 3, 'action' => 'created', 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'timestamp' => '2026-10-01T10:00:00+00:00']],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $expected
     */
    #[DataProvider('storedRows')]
    public function testStoredRowBuildsTheSameMercureMessage(string $storedEventType, array $stored, array $expected): void
    {
        $row = new EventoOutbox(
            aggregateType: 'ticket',
            aggregateId: 100,
            topic: 'tickets',
            tipoDeEvento: $storedEventType,
            payload: $stored,
            sequence: 1,
        );

        $message = (new EventoEnTiempoRealFactory(new TipoDeEventoMapper()))->build($row);

        self::assertSame([
            'eventId' => $stored['event_id'],
            'sequence' => 1,
            'timestamp' => '2026-10-01T10:00:00+00:00',
            'eventType' => $expected['eventType'],
            'ticketId' => '100',
            'payload' => $expected['payload'],
        ], $message);
    }
}
