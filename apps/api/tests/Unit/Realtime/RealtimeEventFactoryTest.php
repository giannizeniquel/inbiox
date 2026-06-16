<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Application\Realtime\EventTypeMapper;
use App\Application\Realtime\RealtimeEventFactory;
use App\Domain\Event\AgentAvailabilityChangedEvent;
use App\Domain\Event\AgentShiftChangedEvent;
use App\Domain\Event\TicketUpdatedEvent;
use App\Domain\Outbox\OutboxEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RealtimeEventFactory — frozen event contract verification.
 *
 * Each test verifies that the payload shape returned by ::build() matches
 * the RealtimeEventContract v1 specification for that event type.
 *
 * @see openspec/changes/sprint-10-agent-management/specs/auto-assignment-audit/spec.md
 * @see openspec/changes/sprint-10-agent-management/specs/agent-availability/spec.md
 */
class RealtimeEventFactoryTest extends TestCase
{
    private RealtimeEventFactory $factory;

    private EventTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new EventTypeMapper();
        $this->factory = new RealtimeEventFactory($this->mapper);
    }

    // ────────────────────────────────────────────────────────────
    //  ticket.assigned
    // ────────────────────────────────────────────────────────────

    /**
     * TicketAssigned payload MUST contain: ticketId, assigneeId,
     * assignmentType, assignedAt.
     *
     * Covers spec: "ticket.assigned event" from auto-assignment-audit/spec.md
     */
    public function testTicketAssignedPayloadShape(): void
    {
        $payload = [
            'event_id'        => 'evt-001',
            'assignee_id'     => 5,
            'assignment_type' => 'AUTO_ASSIGN',
            'assigned_at'     => '2026-06-14T15:30:00Z',
            'occurred_at'     => '2026-06-14T15:30:00Z',
        ];

        $event = new OutboxEvent(
            aggregateType: 'ticket',
            aggregateId: 100,
            topic: 'ticket.100.assigned',
            eventType: TicketUpdatedEvent::class,
            payload: $payload,
            sequence: 1,
        );

        $result = $this->factory->build($event);
        $p = $result['payload'];

        // Envelope contract
        $this->assertArrayHasKey('eventId', $result);
        $this->assertArrayHasKey('sequence', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('eventType', $result);
        $this->assertArrayHasKey('ticketId', $result);
        $this->assertArrayHasKey('payload', $result);
        $this->assertSame('ticket.assigned', $result['eventType']);

        // Payload contract — TicketAssigned uses camelCase keys
        $this->assertArrayHasKey('ticketId', $p);
        $this->assertArrayHasKey('assigneeId', $p);
        $this->assertArrayHasKey('assignmentType', $p);
        $this->assertArrayHasKey('assignedAt', $p);

        // Type checks
        $this->assertIsInt($p['ticketId']);
        $this->assertIsInt($p['assigneeId']);
        $this->assertIsString($p['assignmentType']);
        $this->assertIsString($p['assignedAt']);

        // Value checks
        $this->assertSame(100, $p['ticketId']);
        $this->assertSame(5, $p['assigneeId']);
        $this->assertSame('AUTO_ASSIGN', $p['assignmentType']);
    }

    /**
     * TicketAssigned with MANUAL_ASSIGN type.
     */
    public function testTicketAssignedManualAssign(): void
    {
        $payload = [
            'event_id'        => 'evt-002',
            'assignee_id'     => 3,
            'assignment_type' => 'MANUAL_ASSIGN',
            'assigned_at'     => '2026-06-14T16:00:00Z',
            'occurred_at'     => '2026-06-14T16:00:00Z',
        ];

        $event = new OutboxEvent(
            aggregateType: 'ticket',
            aggregateId: 101,
            topic: 'ticket.101.assigned',
            eventType: TicketUpdatedEvent::class,
            payload: $payload,
        );

        $result = $this->factory->build($event);
        $p = $result['payload'];

        $this->assertSame('ticket.assigned', $result['eventType']);
        $this->assertSame(101, $p['ticketId']);
        $this->assertSame(3, $p['assigneeId']);
        $this->assertSame('MANUAL_ASSIGN', $p['assignmentType']);
    }

    /**
     * TicketAssigned with REASSIGN type.
     */
    public function testTicketAssignedReassign(): void
    {
        $payload = [
            'event_id'        => 'evt-003',
            'assignee_id'     => 8,
            'assignment_type' => 'REASSIGN',
            'previous_agent_id' => 5,
            'assigned_at'     => '2026-06-14T17:00:00Z',
            'occurred_at'     => '2026-06-14T17:00:00Z',
        ];

        $event = new OutboxEvent(
            aggregateType: 'ticket',
            aggregateId: 102,
            topic: 'ticket.102.assigned',
            eventType: TicketUpdatedEvent::class,
            payload: $payload,
        );

        $result = $this->factory->build($event);
        $p = $result['payload'];

        $this->assertSame('ticket.assigned', $result['eventType']);
        $this->assertSame(8, $p['assigneeId']);
        $this->assertSame('REASSIGN', $p['assignmentType']);
    }

    // ────────────────────────────────────────────────────────────
    //  agent.availability_changed
    // ────────────────────────────────────────────────────────────

    /**
     * AgentAvailabilityChanged payload MUST contain: agent_id, is_available,
     * override_active, current_load, max_capacity, timestamp.
     *
     * Covers spec: "agent.availability_changed event" from agent-availability/spec.md
     */
    public function testAgentAvailabilityChangedPayloadShape(): void
    {
        $payload = [
            'event_id'        => 'evt-010',
            'agent_id'        => 5,
            'is_available'    => true,
            'override_active' => true,
            'current_load'    => 3,
            'max_capacity'    => 5,
            'timestamp'       => '2026-06-15T10:00:00Z',
        ];

        $event = new OutboxEvent(
            aggregateType: 'agent',
            aggregateId: 5,
            topic: 'agent.5.availability_changed',
            eventType: AgentAvailabilityChangedEvent::class,
            payload: $payload,
        );

        $result = $this->factory->build($event);
        $p = $result['payload'];

        // Event type
        $this->assertSame('agent.availability_changed', $result['eventType']);

        // Required keys — snake_case
        $this->assertArrayHasKey('agent_id', $p);
        $this->assertArrayHasKey('is_available', $p);
        $this->assertArrayHasKey('override_active', $p);
        $this->assertArrayHasKey('current_load', $p);
        $this->assertArrayHasKey('max_capacity', $p);
        $this->assertArrayHasKey('timestamp', $p);

        // Type checks
        $this->assertIsInt($p['agent_id']);
        $this->assertIsBool($p['is_available']);
        $this->assertIsBool($p['override_active']);
        $this->assertIsInt($p['current_load']);
        $this->assertIsInt($p['max_capacity']);
        $this->assertIsString($p['timestamp']);

        // Value checks
        $this->assertSame(5, $p['agent_id']);
        $this->assertTrue($p['is_available']);
        $this->assertTrue($p['override_active']);
        $this->assertSame(3, $p['current_load']);
        $this->assertSame(5, $p['max_capacity']);
    }

    /**
     * AgentAvailabilityChanged with is_available=false, no override.
     */
    public function testAgentAvailabilityChangedUnavailable(): void
    {
        $payload = [
            'agent_id'        => 7,
            'is_available'    => false,
            'override_active' => false,
            'current_load'    => 5,
            'max_capacity'    => 5,
            'timestamp'       => '2026-06-15T12:00:00Z',
        ];

        $event = new OutboxEvent(
            aggregateType: 'agent',
            aggregateId: 7,
            topic: 'agent.7.availability_changed',
            eventType: AgentAvailabilityChangedEvent::class,
            payload: $payload,
        );

        $result = $this->factory->build($event);
        $p = $result['payload'];

        $this->assertSame('agent.availability_changed', $result['eventType']);
        $this->assertFalse($p['is_available']);
        $this->assertFalse($p['override_active']);
        $this->assertSame(5, $p['current_load']);
    }

    // ────────────────────────────────────────────────────────────
    //  agent.shift_changed
    // ────────────────────────────────────────────────────────────

    /**
     * AgentShiftChanged payload MUST contain: agent_id, shift_id, action,
     * day_of_week, start_time, end_time, timestamp.
     *
     * Covers spec: "agent.shift_changed event" from agent-availability/spec.md
     */
    public function testAgentShiftChangedPayloadShape(): void
    {
        $payload = [
            'event_id'    => 'evt-020',
            'agent_id'    => 5,
            'shift_id'    => 10,
            'action'      => 'created',
            'day_of_week' => 1,
            'start_time'  => '08:00',
            'end_time'    => '16:00',
            'timestamp'   => '2026-06-15T08:00:00Z',
        ];

        $event = new OutboxEvent(
            aggregateType: 'agent',
            aggregateId: 5,
            topic: 'agent.5.shift_changed',
            eventType: AgentShiftChangedEvent::class,
            payload: $payload,
        );

        $result = $this->factory->build($event);
        $p = $result['payload'];

        // Event type
        $this->assertSame('agent.shift_changed', $result['eventType']);

        // Required keys — snake_case
        $this->assertArrayHasKey('agent_id', $p);
        $this->assertArrayHasKey('shift_id', $p);
        $this->assertArrayHasKey('action', $p);
        $this->assertArrayHasKey('day_of_week', $p);
        $this->assertArrayHasKey('start_time', $p);
        $this->assertArrayHasKey('end_time', $p);
        $this->assertArrayHasKey('timestamp', $p);

        // Type checks
        $this->assertIsInt($p['agent_id']);
        $this->assertIsInt($p['shift_id']);
        $this->assertIsString($p['action']);
        $this->assertIsInt($p['day_of_week']);
        $this->assertIsString($p['start_time']);
        $this->assertIsString($p['end_time']);
        $this->assertIsString($p['timestamp']);

        // Value checks
        $this->assertSame(5, $p['agent_id']);
        $this->assertSame(10, $p['shift_id']);
        $this->assertSame('created', $p['action']);
        $this->assertSame(1, $p['day_of_week']);
        $this->assertSame('08:00', $p['start_time']);
        $this->assertSame('16:00', $p['end_time']);
    }

    /**
     * AgentShiftChanged with action=deleted.
     */
    public function testAgentShiftChangedDeleted(): void
    {
        $payload = [
            'agent_id'    => 3,
            'shift_id'    => 15,
            'action'      => 'deleted',
            'day_of_week' => 5,
            'start_time'  => '22:00',
            'end_time'    => '06:00',
            'timestamp'   => '2026-06-19T22:00:00Z',
        ];

        $event = new OutboxEvent(
            aggregateType: 'agent',
            aggregateId: 3,
            topic: 'agent.3.shift_changed',
            eventType: AgentShiftChangedEvent::class,
            payload: $payload,
        );

        $result = $this->factory->build($event);
        $p = $result['payload'];

        $this->assertSame('agent.shift_changed', $result['eventType']);
        $this->assertSame('deleted', $p['action']);
        $this->assertSame(5, $p['day_of_week']);
        $this->assertSame('22:00', $p['start_time']);
        $this->assertSame('06:00', $p['end_time']);
    }
}
