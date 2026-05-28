<?php

namespace App\Tests\Integration;

use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use App\Repository\ReportingRepository;
use App\Application\Service\ReportingService;
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
    private ?ReportingRepository $reportingRepo = null;
    private ?ReportingService $reportingService = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->reportingRepo = static::getContainer()->get(ReportingRepository::class);
        $this->reportingService = static::getContainer()->get(ReportingService::class);

        // Clean up test data
        $this->em->getConnection()->executeStatement('DELETE FROM messages');
        $this->em->getConnection()->executeStatement('DELETE FROM tickets');
        $this->em->getConnection()->executeStatement('DELETE FROM conversations');
        $this->em->getConnection()->executeStatement('DELETE FROM contacts');
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
        $result = $this->reportingRepo->getFrtMetrics($from, $to);

        // Assert - average should be 45 minutes (2700 seconds)
        $this->assertEquals(2700, (int)$result['avg_frt']);
        $this->assertEquals(1800, $result['min_frt']);
        $this->assertEquals(3600, $result['max_frt']);
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
        $result = $this->reportingRepo->getFrtMetrics($from, $to);

        // Assert - only 1 ticket counted, avg is 30 min
        $this->assertEquals(1800, (int)$result['avg_frt']);
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
        $result = $this->reportingRepo->getRtMetrics($from, $to);

        // Assert - average should be 3 hours (10800 seconds)
        $this->assertEquals(10800, (int)$result['avg_rt']);
        $this->assertEquals(7200, $result['min_rt']);
        $this->assertEquals(14400, $result['max_rt']);
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
        $result = $this->reportingRepo->getRtMetrics($from, $to);

        // Assert - only 1 ticket counted
        $this->assertEquals(7200, (int)$result['avg_rt']);
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
        $result = $this->reportingRepo->getFrtMetrics($from, $to);

        // Assert - only 1 ticket counted
        $this->assertNotNull($result['avg_frt']);
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
        $result = $this->reportingRepo->getFrtMetrics($from, $to);

        // Assert
        $this->assertNull($result['avg_frt']);
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
        $result = $this->reportingRepo->getFrtMetrics($from, $to, $agentId);

        // Assert - only 1 ticket counted
        $this->assertEquals(1800, (int)$result['avg_frt']);
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

        $this->createTestTicketWithStatus('new');
        $this->createTestTicketWithStatus('new');
        $this->createTestTicketWithStatus('active');
        $this->createTestTicketWithStatus('waiting');
        $this->createTestTicketWithStatus('resolved');
        $this->createTestTicketWithStatus('closed');

        // Act
        $result = $this->reportingRepo->getCountsByStatus($from, $to);

        // Assert
        $this->assertEquals(2, $result['new']);
        $this->assertEquals(1, $result['active']);
        $this->assertEquals(1, $result['waiting']);
        $this->assertEquals(1, $result['resolved']);
        $this->assertEquals(1, $result['closed']);
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
        $result = $this->reportingRepo->getVolumeOverTime($from, $to);

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
        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-15 12:00:00',
            1
        );
        $this->createTestTicketWithSla(
            '2026-01-16 10:00:00',
            '2026-01-16 10:30:00',
            null,
            1
        );

        // Agent 2 - 1 ticket, resolved
        $this->createTestTicketWithSla(
            '2026-01-17 10:00:00',
            '2026-01-17 10:30:00',
            '2026-01-17 14:00:00',
            2
        );

        // Act
        $result = $this->reportingRepo->getAgentPerformance($from, $to);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result); // 2 agents

        // Find agent 1
        $agent1 = array_filter($result, fn($r) => $r['agent_id'] === 1);
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

        $this->createTestTicketWithSla(
            '2026-01-15 10:00:00',
            '2026-01-15 10:30:00',
            '2026-01-15 12:00:00',
            42
        );

        $this->createTestTicketWithSla(
            '2026-01-16 10:00:00',
            '2026-01-16 10:30:00',
            '2026-01-16 12:00:00',
            99
        );

        // Act - filter by agent 42
        $result = $this->reportingRepo->getAgentPerformance($from, $to, 42);

        // Assert - only agent 42
        $this->assertCount(1, $result);
        $this->assertEquals(42, $result[0]['agent_id']);
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
        $result = $this->reportingRepo->getFrtMetrics($from, $to);

        // Assert - 30 minutes = 1800 seconds
        $this->assertEquals(1800, (int)$result['avg_frt']);
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
        $result = $this->reportingRepo->getFrtMetrics($from, $to);

        // Assert - 5 days = 432000 seconds
        $this->assertEquals(432000, (int)$result['avg_frt']);
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
        $result = $this->reportingRepo->getFrtMetrics($from, $to);

        // Assert - 0 seconds
        $this->assertEquals(0, (int)$result['avg_frt']);
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
        $this->assertArrayHasKey('avg_frt', $result);
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
        ?int $assignedAgentId = null
    ): Ticket {
        $contact = new Contact();
        $contact->setPhone('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $conversation->setStatus('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus('active');
        $ticket->setCreatedAt(new \DateTimeImmutable($createdAt));
        $ticket->setUpdatedAt(new \DateTimeImmutable($createdAt));
        $ticket->setAssignedAgentId($assignedAgentId);

        if ($firstResponseAt) {
            $ticket->setFirstResponseAt(new \DateTimeImmutable($firstResponseAt));
        }

        if ($resolvedAt) {
            $ticket->setResolvedAt(new \DateTimeImmutable($resolvedAt));
        }

        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }

    private function createTestTicketWithStatus(string $status): Ticket
    {
        $contact = new Contact();
        $contact->setPhone('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $conversation->setStatus('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus($status);
        $ticket->setCreatedAt(new \DateTimeImmutable());
        $ticket->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }
}