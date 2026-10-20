<?php

namespace App\Tests\Integration;

use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Reporte\Repository\ReporteRepository;
use App\Reporte\Query\ReporteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for Sprint 09A - Reporting & Metrics.
 *
 * Tests cover:
 * - FRT computed correctly (with date filter)
 * - RT computed correctly (with date filter)
 * - Filters by date work (from/to params)
 * - Agent filter works (agent_id param)
 * - Counts match DB state (by status)
 * - No NULL contamination (filter NULL SLA fields)
 * - Volume grouped by day (tickets_created/tickets_resolved)
 * - Agent performance (per agent metrics)
 * - UTC timezone (dates in UTC)
 * - Edge cases (empty results, extreme values)
 */
class Sprint09AReportingTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?ReporteRepository $reportingRepo = null;
    private ?ReporteService $reportingService = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->reportingRepo = static::getContainer()->get(ReporteRepository::class);
        $this->reportingService = static::getContainer()->get(ReporteService::class);

        // Clean up test data
        $this->em->getConnection()->executeStatement('DELETE FROM messages');
        $this->em->getConnection()->executeStatement('DELETE FROM tickets');
        $this->em->getConnection()->executeStatement('DELETE FROM conversations');
        $this->em->getConnection()->executeStatement('DELETE FROM contacts');
        $this->em->getConnection()->executeStatement('DELETE FROM agent_shifts');
        $this->em->getConnection()->executeStatement('DELETE FROM agents');

        // Clear identity map — direct SQL bypasses ORM cache
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    // ============================================
    // FRT (FIRST RESPONSE TIME) TESTS
    // ============================================

    /**
     * Test FRT computed correctly - with date filter.
     */
    public function testFrtComputedCorrectlyWithDateFilter(): void
    {
        // Arrange - create tickets with first response times
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        // Ticket 1: 30 minutes to first response
        $ticket1 = $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            null
        );

        // Ticket 2: 60 minutes to first response
        $ticket2 = $this->createTestTicketWithSla(
            '2026-01-20 14:00:00',
            '2026-01-20 15:00:00',
            null
        );

        // Act
        $result = $this->reportingRepo->getMetricasDeFrt($from, $to);

        // Assert - average should be 45 minutes (2700 seconds)
        $this->assertEquals(2700, (int)$result['avg_frt_seconds']);
        $this->assertEquals(1800, $result['min_frt_seconds']);
        $this->assertEquals(3600, $result['max_frt_seconds']);
    }

    /**
     * Test FRT filters out NULL first_response_at.
     */
    public function testFrtExcludesNullFirstResponse(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        // Ticket with first response
        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            null
        );

        // Ticket WITHOUT first response (should be excluded)
        $this->createTestTicketWithSla(
            '2026-01-20 14:00:00',
            null,
            null
        );

        // Act
        $result = $this->reportingRepo->getMetricasDeFrt($from, $to);

        // Assert - only 1 ticket counted, avg is 30 min
        $this->assertEquals(1800, (int)$result['avg_frt_seconds']);
    }

    // ============================================
    // RT (RESOLUTION TIME) TESTS
    // ============================================

    /**
     * Test RT computed correctly - with date filter.
     */
    public function testRtComputedCorrectlyWithDateFilter(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        // Ticket 1: 2 hours to resolve
        $ticket1 = $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-15 12:00:00'
        );

        // Ticket 2: 4 hours to resolve
        $ticket2 = $this->createTestTicketWithSla(
            '2026-01-20 08:00:00',
            '2026-01-20 09:00:00',
            '2026-01-20 12:00:00'
        );

        // Act
        $result = $this->reportingRepo->getMetricasDeRt($from, $to);

        // Assert - average should be 3 hours (10800 seconds)
        $this->assertEquals(10800, (int)$result['avg_rt_seconds']);
        $this->assertEquals(7200, $result['min_rt_seconds']);
        $this->assertEquals(14400, $result['max_rt_seconds']);
    }

    /**
     * Test RT excludes NULL resolved_at.
     */
    public function testRtExcludesNullResolvedAt(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        // Ticket with resolution
        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-15 12:00:00'
        );

        // Ticket WITHOUT resolution (should be excluded)
        $this->createTestTicketWithSla(
            '2026-01-20 14:00:00',
            '2026-01-20 15:00:00',
            null
        );

        // Act
        $result = $this->reportingRepo->getMetricasDeRt($from, $to);

        // Assert - only 1 ticket counted
        $this->assertEquals(7200, (int)$result['avg_rt_seconds']);
    }

    // ============================================
    // DATE FILTERS TESTS
    // ============================================

    /**
     * Test filters by date work - from/to params.
     */
    public function testDateFilterExcludesOutsideRange(): void
    {
        // Arrange - one ticket in range, one outside
        $from = new \DateTimeImmutable('2026-01-15 00:00:00');
        $to = new \DateTimeImmutable('2026-01-20 23:59:59');

        // In range
        $this->createTestTicketWithSla(
            '2026-01-16 10:00:00',
            '2026-01-16 10:30:00',
            '2026-01-16 12:00:00'
        );

        // Outside range (December 2025)
        $this->createTestTicketWithSla(
            '2025-12-15 10:00:00',
            '2025-12-15 10:30:00',
            '2025-12-15 12:00:00'
        );

        // Act
        $result = $this->reportingRepo->getMetricasDeFrt($from, $to);

        // Assert - only 1 ticket counted
        $this->assertNotNull($result['avg_frt_seconds']);
    }

    /**
     * Test edge case - empty results when no tickets in range.
     */
    public function testEmptyResultsWhenNoTicketsInRange(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-03-01 00:00:00');
        $to = new \DateTimeImmutable('2026-03-31 23:59:59');

        // Act
        $result = $this->reportingRepo->getMetricasDeFrt($from, $to);

        // Assert
        $this->assertNull($result['avg_frt_seconds']);
    }

    // ============================================
    // AGENT FILTER TESTS
    // ============================================

    /**
     * Test agent filter works - agent_id param.
     */
    public function testAgentFilterFiltersByAgentId(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');
        $agentId = 42;

        // Ticket assigned to agent 42
        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-15 12:00:00',
            $agentId
        );

        // Ticket assigned to different agent
        $this->createTestTicketWithSla(
            '2026-01-20 10:00:00',
            '2026-01-20 10:30:00',
            '2026-01-20 12:00:00',
            99
        );

        // Act - filter by agent 42
        $result = $this->reportingRepo->getMetricasDeFrt($from, $to, $agentId);

        // Assert - only 1 ticket counted
        $this->assertEquals(1800, (int)$result['avg_frt_seconds']);
    }

    // ============================================
    // COUNTS BY STATUS TESTS
    // ============================================

    /**
     * Test counts match DB state - by status.
     */
    public function testCountsByStatusMatchesDbState(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        $this->createTestTicketWithStatus('nuevo');
        $this->createTestTicketWithStatus('nuevo');
        $this->createTestTicketWithStatus('activo');
        $this->createTestTicketWithStatus('en_espera');
        $this->createTestTicketWithStatus('resuelto');
        $this->createTestTicketWithStatus('cerrado');

        // Act
        $result = $this->reportingRepo->getConteosPorEstado($from, $to);

        // Assert
        $this->assertEquals(2, $result['nuevo']);
        $this->assertEquals(1, $result['activo']);
        $this->assertEquals(1, $result['en_espera']);
        $this->assertEquals(1, $result['resuelto']);
        $this->assertEquals(1, $result['cerrado']);
    }

    // ============================================
    // VOLUME OVER TIME TESTS
    // ============================================

    /**
     * Test volume grouped by day - tickets_created/tickets_resolved.
     */
    public function testVolumeGroupedByDay(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-15 00:00:00');
        $to = new \DateTimeImmutable('2026-01-17 23:59:59');

        // Created on Jan 15, resolved on Jan 16
        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-16 12:00:00'
        );

        // Created on Jan 16, resolved on Jan 16
        $this->createTestTicketWithSla(
            '2026-01-16 09:00:00',
            '2026-01-16 09:30:00',
            '2026-01-16 11:00:00'
        );

        // Created on Jan 16, NOT resolved
        $this->createTestTicketWithSla(
            '2026-01-16 14:00:00',
            '2026-01-16 14:30:00',
            null
        );

        // Act
        $result = $this->reportingRepo->getVolumenEnElTiempo($from, $to);

        // Assert - check structure
        $this->assertIsArray($result);

        // Find Jan 15
        $jan15 = array_filter($result, fn($r) => $r['date'] === '2026-01-15');
        $this->assertNotEmpty($jan15);

        // Find Jan 16
        $jan16 = array_filter($result, fn($r) => $r['date'] === '2026-01-16');
        $this->assertNotEmpty($jan16);
    }

    // ============================================
    // AGENT PERFORMANCE TESTS
    // ============================================

    /**
     * Test agent performance - per agent metrics.
     */
    public function testAgentPerformanceReturnsPerAgentMetrics(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        // Agent 1 - 2 tickets, 1 resolved
        $agent1Id = $this->createTestAgent('Agent 1');
        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-15 12:00:00',
            $agent1Id
        );
        $this->createTestTicketWithSla(
            '2026-01-16 10:00:00',
            '2026-01-16 10:30:00',
            null,
            $agent1Id
        );

        // Agent 2 - 1 ticket, resolved
        $agent2Id = $this->createTestAgent('Agent 2');
        $this->createTestTicketWithSla(
            '2026-01-17 10:00:00',
            '2026-01-17 10:30:00',
            '2026-01-17 14:00:00',
            $agent2Id
        );

        // Act
        $result = $this->reportingRepo->getRendimientoDeAgentes($from, $to);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result); // 2 agents

        // Find agent 1
        $agent1 = array_filter($result, fn($r) => $r['agent_id'] === $agent1Id);
        $agent1 = array_values($agent1)[0] ?? null;
        $this->assertNotNull($agent1);
        $this->assertEquals(2, $agent1['tickets_assigned']);
        $this->assertEquals(1, $agent1['tickets_resolved']);
    }

    /**
     * Test agent filter on performance.
     */
    public function testAgentPerformanceWithAgentFilter(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        $agent42Id = $this->createTestAgent('Agent 42');
        $agent99Id = $this->createTestAgent('Agent 99');

        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-15 12:00:00',
            $agent42Id
        );

        $this->createTestTicketWithSla(
            '2026-01-16 10:00:00',
            '2026-01-16 10:30:00',
            '2026-01-16 12:00:00',
            $agent99Id
        );

        // Act - filter by agent 42
        $result = $this->reportingRepo->getRendimientoDeAgentes($from, $to, $agent42Id);

        // Assert - only agent 42
        $this->assertCount(1, $result);
        $this->assertEquals($agent42Id, $result[0]['agent_id']);
    }

    /**
     * Test agent performance filtered by workspace (phone_number_id).
     * SC-1: agent with active workspace sees stats scoped to that workspace,
     *       other agents appear but with zeroed stats (LEFT JOIN semantics).
     */
    public function testAgentPerformanceWithWorkspaceFilter(): void
    {
        // Arrange — 2 workspaces
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        $wsA = 'phone-ws-a';
        $wsB = 'phone-ws-b';

        // Agent in workspace A — 2 tickets
        $agent1Id = $this->createTestAgent('Agent WS-A');
        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00', '2026-01-15 10:30:00', null, $agent1Id, $wsA
        );
        $this->createTestTicketWithSla(
            '2026-01-16 10:00:00', '2026-01-16 10:30:00', '2026-01-16 12:00:00', $agent1Id, $wsA
        );

        // Agent in workspace B — 1 ticket
        $agent2Id = $this->createTestAgent('Agent WS-B');
        $this->createTestTicketWithSla(
            '2026-01-17 10:00:00', '2026-01-17 10:30:00', null, $agent2Id, $wsB
        );

        // Act — filter by workspace A
        $result = $this->reportingRepo->getRendimientoDeAgentes($from, $to, null, $wsA);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result, 'Both agents appear (LEFT JOIN keeps all active agents)');

        // Agent from WS-A — has stats
        $agent1Row = array_values(array_filter($result, fn($r) => $r['agent_id'] === $agent1Id))[0];
        $this->assertEquals(2, $agent1Row['tickets_assigned'], 'Agent WS-A should have 2 tickets');
        $this->assertEquals(1, $agent1Row['tickets_resolved']);

        // Agent from WS-B — appears but with zeroed stats (filtered by JOIN ON clause)
        $agent2Row = array_values(array_filter($result, fn($r) => $r['agent_id'] === $agent2Id))[0];
        $this->assertEquals(0, $agent2Row['tickets_assigned'], 'Agent WS-B should have 0 tickets (workspace filter)');
        $this->assertEquals(0, $agent2Row['tickets_resolved']);
    }

    /**
     * Test agent performance with null workspace returns all agents.
     * SC-5: null phoneNumberId → unfiltered query (backward compat).
     */
    public function testAgentPerformanceNullWorkspaceReturnsAll(): void
    {
        // Arrange — 2 workspaces
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        $wsA = 'phone-ws-null-a';
        $wsB = 'phone-ws-null-b';

        $agent1Id = $this->createTestAgent('Agent Null-A');
        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00', '2026-01-15 10:30:00', null, $agent1Id, $wsA
        );

        $agent2Id = $this->createTestAgent('Agent Null-B');
        $this->createTestTicketWithSla(
            '2026-01-16 10:00:00', '2026-01-16 10:30:00', null, $agent2Id, $wsB
        );

        // Act — null workspace = no filter (backward compatible)
        $result = $this->reportingRepo->getRendimientoDeAgentes($from, $to);

        // Assert — both agents appear
        $agentIds = array_column($result, 'agent_id');
        $this->assertContains($agent1Id, $agentIds);
        $this->assertContains($agent2Id, $agentIds);
    }

    // ============================================
    // UTC TIMEZONE TESTS
    // ============================================

    /**
     * Test dates in UTC.
     */
    public function testDatesAreInUtc(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-02 00:00:00');

        $this->createTestTicketWithSla(
            '2026-01-01 12:00:00', // UTC noon
            '2026-01-01 12:30:00', // UTC 12:30
            '2026-01-01 14:00:00'  // UTC 14:00
        );

        // Act
        $result = $this->reportingRepo->getMetricasDeFrt($from, $to);

        // Assert - 30 minutes = 1800 seconds
        $this->assertEquals(1800, (int)$result['avg_frt_seconds']);
    }

    // ============================================
    // EDGE CASES TESTS
    // ============================================

    /**
     * Test extreme values - very large FRT.
     */
    public function testFrtWithExtremeValues(): void
    {
        // Arrange - ticket with very long FRT (5 days)
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        $this->createTestTicketWithSla(
            '2026-01-01 10:00:00',
            '2026-01-06 10:00:00', // 5 days later
            null
        );

        // Act
        $result = $this->reportingRepo->getMetricasDeFrt($from, $to);

        // Assert - 5 days = 432000 seconds
        $this->assertEquals(432000, (int)$result['avg_frt_seconds']);
    }

    /**
     * Test zero FRT (immediate response).
     */
    public function testFrtWithZeroResponseTime(): void
    {
        // Arrange - immediate response
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:00:00', // Same timestamp = instant response
            null
        );

        // Act
        $result = $this->reportingRepo->getMetricasDeFrt($from, $to);

        // Assert - 0 seconds
        $this->assertEquals(0, (int)$result['avg_frt_seconds']);
    }

    /**
     * Test service layer wraps repository correctly.
     */
    public function testReportingServiceWrapsRepository(): void
    {
        // Arrange
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 23:59:59');

        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-15 12:00:00'
        );

        // Act - use service instead of direct repo
        $result = $this->reportingService->getFrt($from, $to);

        // Assert - service should return same format
        $this->assertArrayHasKey('avg_frt_seconds', $result);
    }

    /**
     * Test service validates date range.
     */
    public function testReportingServiceValidatesDateRange(): void
    {
        // Arrange - invalid range (from after to)
        $from = new \DateTimeImmutable('2026-01-31 00:00:00');
        $to = new \DateTimeImmutable('2026-01-01 00:00:00');

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $this->reportingService->getFrt($from, $to);
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    private function createTestTicketWithSla(
        string $createdAt,
        ?string $firstResponseAt,
        ?string $resolvedAt,
        ?int $assignedAgentId = null,
        ?string $phoneNumberId = null
    ): Ticket {
        $contact = new Contacto();
        $contact->setTelefono('+54' . random_int(3710000000, 3719999999));
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $conversation->setEstado('open');
        if ($phoneNumberId !== null) {
            $conversation->setPhoneNumberId($phoneNumberId);
        }
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado('activo');
        $ticket->setCreadoAt(new \DateTimeImmutable($createdAt));
        $ticket->setActualizadoAt(new \DateTimeImmutable($createdAt));
        $ticket->setAgenteAsignadoId($assignedAgentId);
        if ($phoneNumberId !== null) {
            $ticket->setPhoneNumberId($phoneNumberId);
        }

        if ($firstResponseAt) {
            $ticket->setPrimeraRespuestaAt(new \DateTimeImmutable($firstResponseAt));
        }

        if ($resolvedAt) {
            $ticket->setResueltoAt(new \DateTimeImmutable($resolvedAt));
        }

        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }

    private function createTestAgent(string $name = 'Test Agent'): int
    {
        $agent = new \App\Agente\Agente($name);
        $this->em->persist($agent);
        $this->em->flush();
        return $agent->getId();
    }

    private function createTestTicketWithStatus(string $status): Ticket
    {
        $contact = new Contacto();
        $contact->setTelefono('+54' . random_int(3710000000, 3719999999));
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $conversation->setEstado('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado($status);
        $ticket->setCreadoAt(new \DateTimeImmutable('2026-01-15 12:00:00'));
        $ticket->setActualizadoAt(new \DateTimeImmutable('2026-01-15 12:00:00'));

        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }
}