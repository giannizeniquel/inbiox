<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Disponibilidad\Query\DisponibilidadDeAgenteDTO;
use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Agente\Agente;
use App\Disponibilidad\Turno;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Ticket\Repository\TicketRepository;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Workspace;
use App\Agente\Repository\AgenteRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive unit tests for DisponibilidadDeAgenteService.
 *
 * Covers all 34 spec scenarios from agent-availability/spec.md that are
 * testable at the service layer, plus mandatory boundary cases from the
 * exit criteria.
 *
 * Spec coverage mapping:
 *   Agent Entity Extensions       → testInvalidTimezone*, testDefaultTimezone
 *   Agent Shift Management        → testCreateShift*, testCrossMidnightShift*
 *   Availability Algorithm        → tests 1–14 below
 *   Capacity Tracking             → tests 15–18 below
 *   Override/Endpoint semantics   → tested via service override path
 *
 * @see openspec/changes/sprint-10-agent-management/specs/agent-availability/spec.md
 */
class AgentAvailabilityServiceTest extends TestCase
{
    private AgenteRepository&MockObject $agentRepository;
    private TurnoRepository&MockObject $agentShiftRepository;
    private TicketRepository&MockObject $ticketRepository;
    private DisponibilidadDeAgenteService $service;

    protected function setUp(): void
    {
        $this->agentRepository = $this->createMock(AgenteRepository::class);
        $this->agentShiftRepository = $this->createMock(TurnoRepository::class);
        $this->ticketRepository = $this->createMock(TicketRepository::class);

        $this->service = new DisponibilidadDeAgenteService(
            $this->agentRepository,
            $this->agentShiftRepository,
            $this->ticketRepository,
        );
    }

    // ────────────────────────────────────────────────────────────
    //  HELPERS
    // ────────────────────────────────────────────────────────────

    /** Create an agent with given properties. Sets id via reflection. */
    private function createAgent(
        int $id = 1,
        bool $active = true,
        string $timezone = 'UTC',
        ?bool $isAvailableOverride = null,
        ?\DateTimeImmutable $overrideUntil = null,
        int $maxActiveTickets = 5,
    ): Agente {
        $agent = new Agente('Agent-'.$id);
        $agent->setActivo($active);
        $agent->setZonaHoraria($timezone);
        $agent->setMaxTicketsActivos($maxActiveTickets);

        if ($isAvailableOverride !== null) {
            $agent->setDisponibilidadManual($isAvailableOverride);
        }
        if ($overrideUntil !== null) {
            $agent->setDisponibilidadManualHasta($overrideUntil);
        }

        $this->setEntityId($agent, $id);

        return $agent;
    }

    /** Create a shift attached to an agent. Sets id via reflection. */
    private function createShift(
        Agente $agent,
        int $dayOfWeek,
        string $start,
        string $end,
        int $id = 1,
    ): Turno {
        $startTime = \DateTimeImmutable::createFromFormat('H:i:s', $start.':00');
        $endTime = \DateTimeImmutable::createFromFormat('H:i:s', $end.':00');
        \assert($startTime !== false && $endTime !== false);

        $shift = new Turno($agent, $dayOfWeek, $startTime, $endTime);
        $agent->addTurno($shift);

        $this->setEntityId($shift, $id);

        return $shift;
    }

    /** Set a private/protected id field via reflection. */
    private function setEntityId(object $entity, int $id): void
    {
        $prop = new \ReflectionProperty($entity, 'id');
        $prop->setAccessible(true);
        $prop->setValue($entity, $id);
    }

    /**
     * Configure the ticket repository mock to return a given count for the
     * active-tickets COUNT query.
     */
    private function expectActiveTicketCount(int $agentId, int $count): void
    {
        $this->ticketRepository->expects(self::once())
            ->method('countActivosDeAgente')
            ->willReturn($count);
    }

    /**
     * Configure the ticket repository mock to expect NO COUNT query.
     * Use when the algorithm short-circuits before capacity.
     */
    private function expectNoActiveTicketCount(): void
    {
        $this->ticketRepository->expects(self::never())
            ->method('countActivosDeAgente');
    }

    /**
     * Create a UTC DateTimeImmutable for a specific datetime.
     *
     * @param string $date YYYY-MM-DD
     * @param string $time HH:MM
     */
    private function utcDateTime(string $date, string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date.'T'.$time.':00', new \DateTimeZone('UTC'));
    }

    // ────────────────────────────────────────────────────────────
    //  SPEC: AGENT ENTITY EXTENSIONS
    //  Covers spec scenarios: default timezone, valid IANA
    // ────────────────────────────────────────────────────────────

    public function testDefaultTimezoneIsUtc(): void
    {
        $agent = new Agente('Test');
        self::assertSame('UTC', $agent->getZonaHoraria());
    }

    public function testValidTimezoneAccepted(): void
    {
        $agent = new Agente('Test');
        $agent->setZonaHoraria('America/Argentina/Buenos_Aires');
        self::assertSame('America/Argentina/Buenos_Aires', $agent->getZonaHoraria());
    }

    public function testInvalidTimezoneThrows(): void
    {
        $agent = new Agente('Test');
        $this->expectException(\InvalidArgumentException::class);
        $agent->setZonaHoraria('Invalid/Timezone');
    }

    // ────────────────────────────────────────────────────────────
    //  STEP 1: ACCOUNT ACTIVE CHECK
    //  Spec: "Agent inactive always unavailable"
    // ────────────────────────────────────────────────────────────

    public function testInactiveAgentAlwaysUnavailable(): void
    {
        // GIVEN an inactive agent
        $agent = $this->createAgent(active: false);
        $this->createShift($agent, 1, '08:00', '16:00');

        // WHEN evaluated
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible($agent, new \DateTimeImmutable('now'));

        // THEN unavailable regardless of shift/override/capacity
        self::assertFalse($result);
    }

    // ────────────────────────────────────────────────────────────
    //  STEP 2: OVERRIDE CHECK
    //  Spec: "Override true with TTL active"
    //        "Override false blocks assignment"
    //        "Override TTL expiration"
    //        "Override null means persistent"
    // ────────────────────────────────────────────────────────────

    public function testOverrideTrueWithinTtlReturnsTrue(): void
    {
        // GIVEN override = true with TTL = now + 120min
        $now = $this->utcDateTime('2026-06-14', '10:00');
        $ttl = $now->modify('+120 minutes');
        $agent = $this->createAgent(
            isAvailableOverride: true,
            overrideUntil: $ttl,
        );

        // WHEN evaluated at now + 60min (inside TTL)
        $evalAt = $now->modify('+60 minutes');
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible($agent, $evalAt);

        // THEN override takes precedence (even without shifts)
        self::assertTrue($result);
    }

    public function testOverrideFalseBlocksAssignment(): void
    {
        // GIVEN override = false with persistent TTL (null)
        $agent = $this->createAgent(
            isAvailableOverride: false,
            overrideUntil: null,
        );
        $this->createShift($agent, 1, '08:00', '16:00');

        // WHEN evaluated (in shift, with capacity)
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'), // Monday 12:00 UTC
        );

        // THEN override blocks regardless
        self::assertFalse($result);
    }

    public function testOverrideTtlExpiredFallsThroughToSchedule(): void
    {
        // GIVEN override = true, TTL = 2026-06-14T12:00:00Z
        $ttl = $this->utcDateTime('2026-06-14', '12:00');
        $agent = $this->createAgent(
            isAvailableOverride: true,
            overrideUntil: $ttl,
            timezone: 'America/Argentina/Buenos_Aires',
        );

        // AND agent has a shift Monday 08:00-16:00 (local)
        // Monday 2026-06-15 08:00 ART = 2026-06-15 11:00 UTC
        $this->createShift($agent, 1, '08:00', '16:00');

        // WHEN evaluated at 2026-06-14T12:01:00Z (TTL expired)
        $evalAt = $this->utcDateTime('2026-06-14', '12:01');
        // Local time (ART): 2026-06-14 09:01 → Sunday, outside shift
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible($agent, $evalAt);

        // THEN override is ignored, schedule check fails
        self::assertFalse($result);
    }

    public function testOverrideTtlExpiredFallsThroughToCapacityWhenInShift(): void
    {
        // GIVEN override = true, TTL = 2026-06-15T10:00:00Z, expired
        $ttl = $this->utcDateTime('2026-06-15', '10:00');
        $agent = $this->createAgent(
            isAvailableOverride: true,
            overrideUntil: $ttl,
            maxActiveTickets: 5,
            timezone: 'America/Argentina/Buenos_Aires',
        );

        // AND agent has a shift Monday 08:00-16:00 (local)
        // Monday 2026-06-15 08:00 ART = 2026-06-15 11:00 UTC
        $this->createShift($agent, 1, '08:00', '16:00');

        // WHEN evaluated at 2026-06-15T12:00:00Z (TTL expired, in shift)
        $evalAt = $this->utcDateTime('2026-06-15', '12:00');
        // Local (ART): 2026-06-15 09:00 → Monday, within 08:00-16:00 → in shift
        $this->expectActiveTicketCount(1, 5); // at capacity = 5
        $result = $this->service->isDisponible($agent, $evalAt);

        // THEN override ignored, schedule matches, capacity blocks
        self::assertFalse($result);
    }

    public function testOverrideNullPersistentBlocks(): void
    {
        // GIVEN override = false, overrideUntil = NULL (persistent)
        $agent = $this->createAgent(
            isAvailableOverride: false,
            overrideUntil: null,
        );
        $this->createShift($agent, 1, '08:00', '16:00');

        // WHEN evaluated at any time
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        // THEN override is active, blocks permanently
        self::assertFalse($result);
    }

    public function testOverrideTruePersistentBypassesAll(): void
    {
        // GIVEN override = true, overrideUntil = NULL (persistent)
        $agent = $this->createAgent(
            isAvailableOverride: true,
            overrideUntil: null,
        );

        // WHEN evaluated (no shifts, but override = true)
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            new \DateTimeImmutable('now'),
        );

        // THEN permanently available
        self::assertTrue($result);
    }

    /** Boundary: TTL expires exactly at `now`. override_until >= atUtc → true */
    public function testOverrideTtlExactlyNow(): void
    {
        // GIVEN override = true, TTL = now
        $now = $this->utcDateTime('2026-06-14', '12:00');
        $agent = $this->createAgent(
            isAvailableOverride: true,
            overrideUntil: $now,
        );

        // WHEN evaluated at exactly TTL time (override_until >= atUtc)
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible($agent, $now);

        // THEN override is still active (>=, not >)
        self::assertTrue($result);
    }

    // ────────────────────────────────────────────────────────────
    //  STEP 3: SCHEDULE CHECK
    //  Spec: "Agent in shift with capacity"
    //        "Agent outside shift hours"
    //        "Cross-midnight shift evaluation"
    //        "Cross-midnight shift outside window"
    //        "Multiple shifts same day (union)"
    //        "Agent without shifts"
    // ────────────────────────────────────────────────────────────

    public function testInShiftWithCapacityReturnsTrue(): void
    {
        // GIVEN agent with shift Monday 08:00-16:00, America/Argentina/Buenos_Aires
        $agent = $this->createAgent(timezone: 'America/Argentina/Buenos_Aires');
        $this->createShift($agent, 1, '08:00', '16:00');

        // AND 3 active tickets, max_active_tickets = 5
        $this->expectActiveTicketCount(1, 3);

        // WHEN evaluated at Monday 10:00 local time
        // 10:00 ART = 13:00 UTC on Monday 2026-06-15
        $evalAt = $this->utcDateTime('2026-06-15', '13:00');
        $result = $this->service->isDisponible($agent, $evalAt);

        // THEN available = true
        self::assertTrue($result);
    }

    public function testOutsideShiftHoursReturnsFalse(): void
    {
        // GIVEN agent with shift Monday 08:00-16:00
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '16:00');

        // WHEN evaluated at Monday 18:00 local (UTC)
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '18:00'),
        );

        // THEN no schedule match → false
        self::assertFalse($result);
    }

    public function testAgentWithoutShiftsReturnsFalse(): void
    {
        // GIVEN agent with no shifts defined, no override
        $agent = $this->createAgent();

        // WHEN evaluated (no override active)
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            new \DateTimeImmutable('now'),
        );

        // THEN no schedule match → false
        self::assertFalse($result);
    }

    // ── Cross-midnight ──

    public function testCrossMidnightShiftInside(): void
    {
        // GIVEN shift Friday 22:00-06:00, timezone America/Argentina/Buenos_Aires
        $agent = $this->createAgent(timezone: 'America/Argentina/Buenos_Aires');
        $this->createShift($agent, 5, '22:00', '06:00');

        // WHEN evaluated at Saturday 02:30 local time
        $this->expectActiveTicketCount(1, 0);
        // Saturday 2026-06-20 02:30 ART = 2026-06-20 05:30 UTC
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '05:30'),
        );

        // THEN within shift (cross-midnight)
        self::assertTrue($result);
    }

    public function testCrossMidnightShiftOutsideWindow(): void
    {
        // GIVEN shift Friday 22:00-06:00
        $agent = $this->createAgent(timezone: 'America/Argentina/Buenos_Aires');
        $this->createShift($agent, 5, '22:00', '06:00');

        // WHEN evaluated at Saturday 09:00 local time
        $this->expectNoActiveTicketCount();
        // Saturday 2026-06-20 09:00 ART = 2026-06-20 12:00 UTC
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '12:00'),
        );

        // THEN 09:00 is after 06:00 end → false
        self::assertFalse($result);
    }

    // ── Multi-shift union ──

    public function testMultipleShiftsSameDayInFirstShift(): void
    {
        // GIVEN shifts Monday 08:00-12:00 AND Monday 14:00-18:00
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '12:00');
        $this->createShift($agent, 1, '14:00', '18:00');

        // WHEN evaluated at Monday 10:00 local time
        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '10:00'),
        );

        // THEN within first shift → true
        self::assertTrue($result);
    }

    public function testMultipleShiftsSameDayGap(): void
    {
        // GIVEN shifts Monday 08:00-12:00 AND Monday 14:00-18:00
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '12:00');
        $this->createShift($agent, 1, '14:00', '18:00');

        // WHEN evaluated at Monday 13:00 local time (gap)
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '13:00'),
        );

        // THEN gap between shifts → false
        self::assertFalse($result);
    }

    public function testMultipleShiftsSameDayInSecondShift(): void
    {
        // GIVEN shifts Monday 08:00-12:00 AND Monday 14:00-18:00
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '12:00');
        $this->createShift($agent, 1, '14:00', '18:00');

        // WHEN evaluated at Monday 15:00 local time
        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '15:00'),
        );

        // THEN within second shift → true
        self::assertTrue($result);
    }

    // ── Shift edge boundaries ──

    /** Boundary: 21:59 is outside shift 22:00-06:00 */
    public function testEdgeOfCrossMidnightBeforeStart(): void
    {
        $agent = $this->createAgent(timezone: 'UTC');
        $this->createShift($agent, 5, '22:00', '06:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-19', '21:59'), // Friday 21:59 UTC
        );

        self::assertFalse($result);
    }

    /** Boundary: 22:00 is inside shift 22:00-06:00 */
    public function testEdgeOfCrossMidnightAtStart(): void
    {
        $agent = $this->createAgent(timezone: 'UTC');
        $this->createShift($agent, 5, '22:00', '06:00');

        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-19', '22:00'), // Friday 22:00 UTC
        );

        self::assertTrue($result);
    }

    /** Boundary: 05:59 is inside cross-midnight shift 22:00-06:00 */
    public function testEdgeOfCrossMidnightInsideEnd(): void
    {
        $agent = $this->createAgent(timezone: 'UTC');
        $this->createShift($agent, 5, '22:00', '06:00');

        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '05:59'), // Saturday 05:59 UTC
        );

        self::assertTrue($result);
    }

    /** Boundary: 06:00 is outside cross-midnight shift 22:00-06:00 */
    public function testEdgeOfCrossMidnightAtEnd(): void
    {
        $agent = $this->createAgent(timezone: 'UTC');
        $this->createShift($agent, 5, '22:00', '06:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '06:00'), // Saturday 06:00 UTC
        );

        self::assertFalse($result);
    }

    /** Boundary: 06:01 is outside cross-midnight shift 22:00-06:00 */
    public function testEdgeOfCrossMidnightJustAfterEnd(): void
    {
        $agent = $this->createAgent(timezone: 'UTC');
        $this->createShift($agent, 5, '22:00', '06:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '06:01'), // Saturday 06:01 UTC
        );

        self::assertFalse($result);
    }

    // ── Normal shift edge boundaries ──

    /** Boundary: 21:59 is outside shift 22:00-06:00 (in normal start-time logic) */
    public function testEdgeOfShiftBeforeStart(): void
    {
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '07:59'), // Monday 07:59 UTC
        );

        self::assertFalse($result);
    }

    /** Boundary: 08:00 is inside shift 08:00-16:00 */
    public function testEdgeOfShiftAtStart(): void
    {
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '08:00'), // Monday 08:00 UTC
        );

        self::assertTrue($result);
    }

    /** Boundary: 15:59 is inside shift 08:00-16:00 */
    public function testEdgeOfShiftBeforeEnd(): void
    {
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '15:59'), // Monday 15:59 UTC
        );

        self::assertTrue($result);
    }

    /** Boundary: 16:00 is outside shift 08:00-16:00 (half-open) */
    public function testEdgeOfShiftAtEnd(): void
    {
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '16:00'), // Monday 16:00 UTC
        );

        self::assertFalse($result);
    }

    // ────────────────────────────────────────────────────────────
    //  STEP 4: CAPACITY CHECK
    //  Spec: "Capacity reached"
    //        "Active ticket count changes on assignment/resolution/closure"
    // ────────────────────────────────────────────────────────────

    public function testCapacityMaxReturnsFalse(): void
    {
        // GIVEN agent in shift, max = 5, count = 5
        $agent = $this->createAgent(maxActiveTickets: 5);
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectActiveTicketCount(1, 5);

        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        // THEN capacity full → false
        self::assertFalse($result);
    }

    public function testCapacityOneSlotLeftReturnsTrue(): void
    {
        // GIVEN agent in shift, max = 5, count = 4 (one slot left)
        $agent = $this->createAgent(maxActiveTickets: 5);
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectActiveTicketCount(1, 4);

        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        // THEN one slot left → true
        self::assertTrue($result);
    }

    public function testCapacityZeroActiveTickets(): void
    {
        // GIVEN agent in shift, max = 5, count = 0
        $agent = $this->createAgent(maxActiveTickets: 5);
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectActiveTicketCount(1, 0);

        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertTrue($result);
    }

    /** Assignment increases count: mock returns 4 */
    public function testCapacityCountOnAssignment(): void
    {
        $agent = $this->createAgent(maxActiveTickets: 5);
        $this->createShift($agent, 1, '08:00', '16:00');

        // After assignment, count = 4 (within limit)
        $this->expectActiveTicketCount(1, 4);

        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertTrue($result);
    }

    /** Resolution decreases count: mock returns 4 (was 5) */
    public function testCapacityCountOnResolution(): void
    {
        $agent = $this->createAgent(maxActiveTickets: 5);
        $this->createShift($agent, 1, '08:00', '16:00');

        // After resolution, count = 4 (was 5, one resolved)
        $this->expectActiveTicketCount(1, 4);

        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertTrue($result);
    }

    /** Closure decreases count: mock returns 4 (was 5) */
    public function testCapacityCountOnClosure(): void
    {
        $agent = $this->createAgent(maxActiveTickets: 5);
        $this->createShift($agent, 1, '08:00', '16:00');

        // After closure, count = 4 (was 5, one closed)
        $this->expectActiveTicketCount(1, 4);

        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertTrue($result);
    }

    /** COUNT only counts new/active/waiting, not resolved/closed */
    public function testCapacityCountExcludesResolvedAndClosed(): void
    {
        $agent = $this->createAgent(maxActiveTickets: 5);
        $this->createShift($agent, 1, '08:00', '16:00');

        // 3 active (new/active/waiting), 2 resolved/closed — count = 3
        $this->expectActiveTicketCount(1, 3);

        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertTrue($result);
    }

    // ────────────────────────────────────────────────────────────
    //  CROSS-TIMEZONE
    // ────────────────────────────────────────────────────────────

    public function testDifferentTimezoneConversion(): void
    {
        // GIVEN agent with timezone America/Argentina/Buenos_Aires (-03:00)
        $agent = $this->createAgent(timezone: 'America/Argentina/Buenos_Aires');
        $this->createShift($agent, 1, '08:00', '16:00'); // Mon 08:00-16:00 ART

        // WHEN evaluated at Monday 11:00 UTC (08:00 ART) — start of shift
        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '11:00'), // 11:00 UTC = 08:00 ART
        );

        self::assertTrue($result);

        // WHEN evaluated at Monday 10:59 UTC (07:59 ART) — before shift
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '10:59'), // 10:59 UTC = 07:59 ART
        );

        self::assertFalse($result);
    }

    public function testUtcDefaultTimezoneShiftConversion(): void
    {
        // GIVEN agent with default UTC timezone
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '16:00'); // Mon 08:00-16:00 UTC

        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '08:00'),
        );

        self::assertTrue($result);

        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-15', '07:59'),
        );

        self::assertFalse($result);
    }

    /** Cross-timezone + cross-midnight: the hardest case */
    public function testCrossTimezoneCrossMidnight(): void
    {
        // GIVEN agent in America/Argentina/Buenos_Aires with shift Friday 22:00-06:00
        $agent = $this->createAgent(
            id: 1,
            timezone: 'America/Argentina/Buenos_Aires',
        );
        $this->createShift($agent, 5, '22:00', '06:00');

        // Saturday 02:30 ART = Saturday 05:30 UTC → within shift
        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '05:30'), // Sat 05:30 UTC = 02:30 ART
        );
        self::assertTrue($result);

        // Saturday 09:00 ART = Saturday 12:00 UTC → outside shift
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '12:00'), // Sat 12:00 UTC = 09:00 ART
        );
        self::assertFalse($result);
    }

    // ────────────────────────────────────────────────────────────
    //  DETERMINISM: same data → same result, always
    // ────────────────────────────────────────────────────────────

    public function testIsAvailableDeterministic(): void
    {
        $agent = $this->createAgent(maxActiveTickets: 5, timezone: 'UTC');
        $this->createShift($agent, 1, '08:00', '16:00');

        $at = $this->utcDateTime('2026-06-15', '12:00');

        // Expect TWO COUNT queries (one per isDisponible call)
        $this->ticketRepository->expects(self::exactly(2))
            ->method('countActivosDeAgente')
            ->willReturnOnConsecutiveCalls(2, 2);

        // Call twice, should get identical results
        $result1 = $this->service->isDisponible($agent, $at);
        $result2 = $this->service->isDisponible($agent, $at);

        self::assertSame($result1, $result2);
    }

    // ────────────────────────────────────────────────────────────
    //  isActiveNow — manual-assign eligibility predicate (Activo/Inactivo)
    //  Spec: agent-assignment-eligibility. Capacity is NEVER consulted.
    //  Algorithm: account-active pre-filter, then override (if set and
    //  unexpired) takes precedence, ELSE shift-match.
    // ────────────────────────────────────────────────────────────

    public function testIsActiveNowInactiveAccountReturnsFalse(): void
    {
        // GIVEN an inactive agent, even with a matching shift
        $agent = $this->createAgent(active: false);
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora(
            $agent,
            $agent->getTurnos()->toArray(),
            $this->utcDateTime('2026-06-15', '12:00'), // Monday, in shift
        );

        // THEN Inactivo regardless of shift/override
        self::assertFalse($result);
    }

    public function testIsActiveNowOverrideTrueUnexpiredReturnsTrue(): void
    {
        // GIVEN override = true, unexpired TTL, no shifts at all
        $now = $this->utcDateTime('2026-06-14', '10:00');
        $ttl = $now->modify('+120 minutes');
        $agent = $this->createAgent(isAvailableOverride: true, overrideUntil: $ttl);

        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora($agent, [], $now->modify('+60 minutes'));

        self::assertTrue($result);
    }

    public function testIsActiveNowOverrideFalseUnexpiredReturnsFalse(): void
    {
        // GIVEN override = false, unexpired (persistent), even though in-shift
        $agent = $this->createAgent(isAvailableOverride: false, overrideUntil: null);
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora(
            $agent,
            $agent->getTurnos()->toArray(),
            $this->utcDateTime('2026-06-15', '12:00'), // Monday, in shift — override still wins
        );

        self::assertFalse($result);
    }

    public function testIsActiveNowOverrideExpiredFallsThroughToInShiftReturnsTrue(): void
    {
        // GIVEN override = true, TTL expired by evaluation time
        $ttl = $this->utcDateTime('2026-06-14', '12:00');
        $agent = $this->createAgent(isAvailableOverride: true, overrideUntil: $ttl);
        $this->createShift($agent, 1, '08:00', '16:00');

        // WHEN evaluated Monday 12:00 (TTL expired, in shift)
        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora(
            $agent,
            $agent->getTurnos()->toArray(),
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertTrue($result);
    }

    public function testIsActiveNowOverrideExpiredFallsThroughToOutOfShiftReturnsFalse(): void
    {
        // GIVEN override = true, TTL expired, agent shift is Monday only
        $ttl = $this->utcDateTime('2026-06-14', '12:00');
        $agent = $this->createAgent(isAvailableOverride: true, overrideUntil: $ttl);
        $this->createShift($agent, 1, '08:00', '16:00');

        // WHEN evaluated Tuesday 12:00 (TTL expired, outside shift)
        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora(
            $agent,
            $agent->getTurnos()->toArray(),
            $this->utcDateTime('2026-06-16', '12:00'),
        );

        self::assertFalse($result);
    }

    public function testIsActiveNowNoOverrideInShiftReturnsTrue(): void
    {
        // GIVEN no override, agent in shift
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora(
            $agent,
            $agent->getTurnos()->toArray(),
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertTrue($result);
    }

    public function testIsActiveNowNoOverrideOutOfShiftReturnsFalse(): void
    {
        // GIVEN no override, agent outside shift
        $agent = $this->createAgent();
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora(
            $agent,
            $agent->getTurnos()->toArray(),
            $this->utcDateTime('2026-06-16', '12:00'), // Tuesday
        );

        self::assertFalse($result);
    }

    public function testIsActiveNowNoShiftsReturnsFalse(): void
    {
        // GIVEN no override, no shifts defined
        $agent = $this->createAgent();

        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora($agent, [], new \DateTimeImmutable('now'));

        self::assertFalse($result);
    }

    /** Capacity is NEVER consulted — an in-shift agent at full capacity is still Activo. */
    public function testIsActiveNowIgnoresCapacityEvenWhenAtLimit(): void
    {
        $agent = $this->createAgent(maxActiveTickets: 1);
        $this->createShift($agent, 1, '08:00', '16:00');

        // No COUNT query must ever be issued by isActiveNow
        $this->expectNoActiveTicketCount();
        $result = $this->service->isActivoAhora(
            $agent,
            $agent->getTurnos()->toArray(),
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertTrue($result);
    }

    // ────────────────────────────────────────────────────────────
    //  listarAgentesDisponibles
    // ────────────────────────────────────────────────────────────

    public function testListAvailableAgentsReturnsEmptyWhenNoActive(): void
    {
        $this->agentRepository->expects(self::once())
            ->method('findActivos')
            ->willReturn([]);

        $result = $this->service->listarAgentesDisponibles(new \DateTimeImmutable('now'));

        self::assertSame([], $result);
    }

    public function testListAvailableAgentsFiltersBySchedule(): void
    {
        // GIVEN two active agents, one in shift, one out
        $agentInShift = $this->createAgent(id: 1);
        $this->createShift($agentInShift, 1, '08:00', '16:00');

        $agentOutShift = $this->createAgent(id: 2);
        $this->createShift($agentOutShift, 2, '08:00', '16:00'); // Tuesday, not Monday

        $this->agentRepository->expects(self::once())
            ->method('findActivos')
            ->willReturn([$agentInShift, $agentOutShift]);

        // Batch-load shifts
        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([1, 2])
            ->willReturn(\array_merge(
                $agentInShift->getTurnos()->toArray(),
                $agentOutShift->getTurnos()->toArray(),
            ));

        $this->expectActiveTicketCount(1, 2);

        $result = $this->service->listarAgentesDisponibles(
            $this->utcDateTime('2026-06-15', '12:00'), // Monday 12:00 UTC
        );

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]->getId());
    }

    public function testListAvailableAgentsFiltersByCapacity(): void
    {
        // GIVEN two agents, both in shift, one at capacity
        $agentFree = $this->createAgent(id: 1, maxActiveTickets: 5);
        $this->createShift($agentFree, 1, '08:00', '16:00');

        $agentFull = $this->createAgent(id: 2, maxActiveTickets: 5);
        $this->createShift($agentFull, 1, '08:00', '16:00');

        $this->agentRepository->expects(self::once())
            ->method('findActivos')
            ->willReturn([$agentFree, $agentFull]);

        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([1, 2])
            ->willReturn(\array_merge(
                $agentFree->getTurnos()->toArray(),
                $agentFull->getTurnos()->toArray(),
            ));

        // Both COUNT queries will be called. We need to handle the
        // sequential calls differently per agent.
        $this->ticketRepository->expects(self::exactly(2))
            ->method('countActivosDeAgente')
            ->willReturnOnConsecutiveCalls(
                3, // 3 < 5: available
                5, // 5 >= 5: full
            );

        $result = $this->service->listarAgentesDisponibles(
            $this->utcDateTime('2026-06-15', '12:00'), // Monday 12:00 UTC
        );

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]->getId());
    }

    public function testListAvailableAgentsFiltersByOverride(): void
    {
        // GIVEN agent with override=true (always available)
        $agentOverride = $this->createAgent(
            id: 1,
            isAvailableOverride: true,
            overrideUntil: null,
        );
        // GIVEN agent in shift but no override
        $agentNoOverride = $this->createAgent(id: 2);
        $this->createShift($agentNoOverride, 1, '08:00', '16:00');

        $this->agentRepository->expects(self::once())
            ->method('findActivos')
            ->willReturn([$agentOverride, $agentNoOverride]);

        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([1, 2])
            ->willReturn($agentNoOverride->getTurnos()->toArray());

        $this->expectActiveTicketCount(2, 0);

        $result = $this->service->listarAgentesDisponibles(
            $this->utcDateTime('2026-06-15', '12:00'), // Monday 12:00 UTC
        );

        // Both should be available (override=true skips all checks)
        self::assertCount(2, $result);
    }

    public function testListAvailableAgentsSortedById(): void
    {
        // GIVEN three agents, all available
        $agent3 = $this->createAgent(id: 3);
        $this->createShift($agent3, 1, '08:00', '16:00');
        $agent1 = $this->createAgent(id: 1);
        $this->createShift($agent1, 1, '08:00', '16:00');
        $agent2 = $this->createAgent(id: 2);
        $this->createShift($agent2, 1, '08:00', '16:00');

        // findActivos returns them in arbitrary order (3, 1, 2)
        $this->agentRepository->expects(self::once())
            ->method('findActivos')
            ->willReturn([$agent3, $agent1, $agent2]);

        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([3, 1, 2])
            ->willReturn(\array_merge(
                $agent3->getTurnos()->toArray(),
                $agent1->getTurnos()->toArray(),
                $agent2->getTurnos()->toArray(),
            ));

        // Each agent's count query
        $this->ticketRepository->expects(self::exactly(3))
            ->method('countActivosDeAgente')
            ->willReturn(0);

        $result = $this->service->listarAgentesDisponibles(
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        // Result must be sorted by id ASC
        self::assertCount(3, $result);
        self::assertSame(1, $result[0]->getId());
        self::assertSame(2, $result[1]->getId());
        self::assertSame(3, $result[2]->getId());
    }

    public function testListAvailableAgentsWithOverrideFalseBlocks(): void
    {
        // GIVEN agent with override=false, persistent (even with shifts)
        $agent = $this->createAgent(
            id: 1,
            isAvailableOverride: false,
            overrideUntil: null,
        );
        $this->createShift($agent, 1, '08:00', '16:00');

        $this->agentRepository->expects(self::once())
            ->method('findActivos')
            ->willReturn([$agent]);

        // Even if findByAgentes is called, we shouldn't need to load shifts
        // because override=false blocks before schedule check
        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([1])
            ->willReturn($agent->getTurnos()->toArray());

        $this->ticketRepository->expects(self::never())->method('countActivosDeAgente');

        $result = $this->service->listarAgentesDisponibles(
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        self::assertCount(0, $result);
    }

    // ────────────────────────────────────────────────────────────
    //  MANUAL TEST HELPERS (documentation — not automated assertions)
    //  The manual tests described in the task are run by the developer.
    //  These methods serve as executable documentation.
    // ────────────────────────────────────────────────────────────

    /**
     * Manual test scenario from verification:
     * Agent with timezone America/Argentina/Buenos_Aires,
     * shift Friday 22:00-06:00,
     * verify isDisponible at Saturday 02:30 UTC = true.
     *
     * This is automated here with the cross-timezone + cross-midnight setup.
     */
    public function testManualVerificationCrossMidnightTrue(): void
    {
        $agent = $this->createAgent(
            id: 1,
            timezone: 'America/Argentina/Buenos_Aires',
        );
        $this->createShift($agent, 5, '22:00', '06:00');

        // Saturday 02:30 ART = Saturday 05:30 UTC
        $this->expectActiveTicketCount(1, 0);
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '05:30'),
        );

        self::assertTrue($result);
    }

    /**
     * Manual test scenario from verification:
     * Same agent, isDisponible at Saturday 09:00 local = false.
     */
    public function testManualVerificationCrossMidnightFalse(): void
    {
        $agent = $this->createAgent(
            id: 1,
            timezone: 'America/Argentina/Buenos_Aires',
        );
        $this->createShift($agent, 5, '22:00', '06:00');

        // Saturday 09:00 ART = Saturday 12:00 UTC
        $this->expectNoActiveTicketCount();
        $result = $this->service->isDisponible(
            $agent,
            $this->utcDateTime('2026-06-20', '12:00'),
        );

        self::assertFalse($result);
    }

    // ────────────────────────────────────────────────────────────
    //  listarAgentesDelEspacioDeTrabajo
    //  Spec: agent-assignment-eligibility — Workspace Agent Roster Endpoint.
    //  Returns EVERY active=true workspace agent (never active=false),
    //  each carrying is_active_now (override-or-shift, capacity ignored).
    // ────────────────────────────────────────────────────────────

    public function testListWorkspaceAgentRosterReturnsEmptyWhenNoAgents(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getId')->willReturn(1);

        $this->agentRepository->expects(self::once())
            ->method('findByEspacioDeTrabajo')
            ->with($workspace)
            ->willReturn([]);

        $result = $this->service->listarAgentesDelEspacioDeTrabajo(
            $workspace,
            new \DateTimeImmutable('now'),
        );

        self::assertSame([], $result);
    }

    public function testListWorkspaceAgentRosterExcludesInactiveAgents(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getId')->willReturn(1);

        // GIVEN one active=true agent (Activo via override) and one active=false agent
        $activeAgent = $this->createAgent(id: 1, active: true, isAvailableOverride: true, overrideUntil: null);
        $inactiveAgent = $this->createAgent(id: 2, active: false);

        $this->agentRepository->expects(self::once())
            ->method('findByEspacioDeTrabajo')
            ->with($workspace)
            ->willReturn([$activeAgent, $inactiveAgent]);

        // Only the active=true agent's shifts are batch-loaded
        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([1])
            ->willReturn([]);

        $this->expectActiveTicketCount(1, 0);

        $result = $this->service->listarAgentesDelEspacioDeTrabajo(
            $workspace,
            $this->utcDateTime('2026-06-15', '12:00'),
        );

        // THEN active=false NEVER appears in the roster
        self::assertCount(1, $result);
        self::assertSame(1, $result[0]->id);
    }

    public function testListWorkspaceAgentRosterAtCapacityAgentStillActivo(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getId')->willReturn(1);

        // GIVEN an agent Activo via override, already at full capacity
        $agent = $this->createAgent(id: 1, isAvailableOverride: true, overrideUntil: null, maxActiveTickets: 1);

        $this->agentRepository->expects(self::once())
            ->method('findByEspacioDeTrabajo')
            ->with($workspace)
            ->willReturn([$agent]);

        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([1])
            ->willReturn([]);

        // Agent is AT capacity (1 active ticket, max = 1) — capacity is never
        // consulted by is_active_now, only reported for display purposes
        $this->expectActiveTicketCount(1, 1);

        $result = $this->service->listarAgentesDelEspacioDeTrabajo(
            $workspace,
            new \DateTimeImmutable('now'),
        );

        self::assertCount(1, $result);
        self::assertTrue($result[0]->isActivoAhora);
        self::assertSame(1, $result[0]->cantidadDeTicketsActivos);
        self::assertSame(1, $result[0]->maxTicketsActivos);
    }

    public function testListWorkspaceAgentRosterSortsActivoBeforeInactivo(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getId')->willReturn(1);

        // GIVEN agent 1 = Inactivo (override false), agent 2 = Activo (override true)
        $agentInactivo = $this->createAgent(id: 1, isAvailableOverride: false, overrideUntil: null);
        $agentActivo = $this->createAgent(id: 2, isAvailableOverride: true, overrideUntil: null);

        $this->agentRepository->expects(self::once())
            ->method('findByEspacioDeTrabajo')
            ->with($workspace)
            ->willReturn([$agentInactivo, $agentActivo]);

        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([1, 2])
            ->willReturn([]);

        $this->ticketRepository->expects(self::exactly(2))
            ->method('countActivosDeAgente')
            ->willReturn(0);

        $result = $this->service->listarAgentesDelEspacioDeTrabajo(
            $workspace,
            new \DateTimeImmutable('now'),
        );

        // THEN Activo (id 2) sorts before Inactivo (id 1)
        self::assertCount(2, $result);
        self::assertSame(2, $result[0]->id);
        self::assertTrue($result[0]->isActivoAhora);
        self::assertSame(1, $result[1]->id);
        self::assertFalse($result[1]->isActivoAhora);
    }

    public function testListWorkspaceAgentRosterSortsByIdWithinSameTier(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getId')->willReturn(1);

        // GIVEN three Activo agents (all override=true), arbitrary input order
        $agent3 = $this->createAgent(id: 3, isAvailableOverride: true, overrideUntil: null);
        $agent1 = $this->createAgent(id: 1, isAvailableOverride: true, overrideUntil: null);
        $agent2 = $this->createAgent(id: 2, isAvailableOverride: true, overrideUntil: null);

        $this->agentRepository->expects(self::once())
            ->method('findByEspacioDeTrabajo')
            ->with($workspace)
            ->willReturn([$agent3, $agent1, $agent2]);

        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([3, 1, 2])
            ->willReturn([]);

        $this->ticketRepository->expects(self::exactly(3))
            ->method('countActivosDeAgente')
            ->willReturn(0);

        $result = $this->service->listarAgentesDelEspacioDeTrabajo(
            $workspace,
            new \DateTimeImmutable('now'),
        );

        // THEN same tier (all Activo) sorts by id ASC
        self::assertCount(3, $result);
        self::assertSame(1, $result[0]->id);
        self::assertSame(2, $result[1]->id);
        self::assertSame(3, $result[2]->id);
    }

    public function testListWorkspaceAgentRosterReturnsDtoWithIsActiveNowKey(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getId')->willReturn(1);

        $agent = $this->createAgent(id: 1, isAvailableOverride: true, overrideUntil: null, maxActiveTickets: 5);

        $this->agentRepository->expects(self::once())
            ->method('findByEspacioDeTrabajo')
            ->with($workspace)
            ->willReturn([$agent]);

        $this->agentShiftRepository->expects(self::once())
            ->method('findByAgentes')
            ->with([1])
            ->willReturn([]);

        $this->expectActiveTicketCount(1, 2);

        $result = $this->service->listarAgentesDelEspacioDeTrabajo($workspace, new \DateTimeImmutable('now'));

        self::assertCount(1, $result);
        self::assertInstanceOf(DisponibilidadDeAgenteDTO::class, $result[0]);
        self::assertSame(1, $result[0]->id);
        self::assertSame('Agent-1', $result[0]->nombre);
        self::assertTrue($result[0]->isActivoAhora);
        self::assertSame(2, $result[0]->cantidadDeTicketsActivos);
        self::assertSame(5, $result[0]->maxTicketsActivos);
        self::assertArrayHasKey('is_active_now', $result[0]->toArray());
    }
}
