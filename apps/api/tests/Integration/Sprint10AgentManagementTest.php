<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\UseCase\AutoAssignTicketUseCase;
use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentShift;
use App\Domain\Assignment\Assignment;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for Sprint 10 Agent Management — PR3.
 *
 * Covers:
 *   - Full auto-assignment flow (agent → shift → ticket → assign)
 *   - Audit trail (Assignment row created with correct type)
 *   - Outbox event queued
 *   - Deterministic tiebreaker behavior
 */
class Sprint10AgentManagementTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Clean all relevant tables (order respects FK constraints)
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM rule_execution_logs');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM agent_shifts');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    /**
     * Full flow: Create agent with shift → Post ticket → Auto-assign → Verify.
     *
     * T-14.1: Assert ticket.owner_agent_id = agent.id
     * T-14.2: Assert Assignment row created with type=AUTO_ASSIGN
     * T-14.3: Assert outbox event queued
     */
    public function testFullAutoAssignmentFlow(): void
    {
        // 1. Create an agent
        $agent = new Agent('Test Agent');
        $agent->setMaxActiveTickets(5);
        $agent->setTimezone('UTC');
        $this->em->persist($agent);
        $this->em->flush();

        $agentId = $agent->getId();

        // 2. Create 24/7 shifts for the agent (all days of week)
        $shiftStart = new \DateTimeImmutable('00:00:00');
        $shiftEnd = new \DateTimeImmutable('23:59:59');
        for ($dow = 1; $dow <= 7; $dow++) {
            $shift = new AgentShift($agent, $dow, $shiftStart, $shiftEnd);
            $this->em->persist($shift);
        }
        $this->em->flush();

        // 3. Create an unassigned ticket
        $ticket = new Ticket();
        $ticket->setStatus(TicketStatus::NEW->value);
        $ticket->setCreatedAt(new \DateTimeImmutable());
        $ticket->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($ticket);
        $this->em->flush();

        $ticketId = (int) $ticket->getId();
        $this->assertNotNull($ticketId);

        // 4. Run auto-assignment
        /** @var AutoAssignTicketUseCase $useCase */
        $useCase = static::getContainer()->get(AutoAssignTicketUseCase::class);
        $result = $useCase->execute($ticketId);

        // 5. Assert result
        $this->assertTrue($result['assigned'], 'Ticket should be assigned');
        $this->assertEquals($ticketId, $result['ticket_id']);
        $this->assertEquals($agentId, $result['agent_id'], 'Should assign to the only available agent');
        $this->assertEquals('success', $result['reason']);

        // 6. Assert ticket.assigned_agent_id was updated
        $this->em->clear();
        $savedTicket = $this->em->getConnection()->executeQuery(
            'SELECT assigned_agent_id, status FROM tickets WHERE id = ?',
            [$ticketId],
        )->fetchAssociative();

        $this->assertNotNull($savedTicket);
        $this->assertEquals($agentId, (int) $savedTicket['assigned_agent_id'], 'Ticket should have owner_agent_id set');
        $this->assertEquals(TicketStatus::ACTIVE->value, $savedTicket['status'], 'Ticket status should be ACTIVE after assignment');

        // 7. Assert Assignment row created with type=AUTO_ASSIGN
        $assignmentRow = $this->em->getConnection()->executeQuery(
            'SELECT * FROM assignments WHERE ticket_id = ? ORDER BY assigned_at DESC LIMIT 1',
            [$ticketId],
        )->fetchAssociative();

        $this->assertNotEmpty($assignmentRow, 'Assignment row should exist');
        $this->assertEquals('AUTO_ASSIGN', $assignmentRow['type']);
        $this->assertEquals($agentId, (int) $assignmentRow['agent_id']);
        $this->assertEquals('system', $assignmentRow['assigned_by']);
        $this->assertNull($assignmentRow['previous_agent_id']);

        // 8. Assert outbox event queued
        $outboxCount = (int) $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM outbox_events WHERE aggregate_id = ?',
            [$ticketId],
        )->fetchOne();

        $this->assertGreaterThan(0, $outboxCount, 'At least one outbox event should be queued');
    }

    /**
     * Deterministic tiebreaker: same data = same agent selected.
     *
     * With 3 agents at equal load and equal today count, the lowest agent.id
     * should be selected. This tests the exact scenario from the spec:
     * Tiebreaker 2 — equal load and equal today, lowest ID wins.
     */
    public function testDeterministicTiebreakerLowestId(): void
    {
        $agents = [];
        for ($i = 1; $i <= 3; $i++) {
            $agent = new Agent("Agent $i");
            $agent->setMaxActiveTickets(10);
            $agent->setTimezone('UTC');
            $this->em->persist($agent);
            $this->em->flush();
            $agents[] = $agent;
        }

        // All agents get 24/7 shifts (all days of week)
        $shiftStart = new \DateTimeImmutable('00:00:00');
        $shiftEnd = new \DateTimeImmutable('23:59:59');
        foreach ($agents as $agent) {
            for ($dow = 1; $dow <= 7; $dow++) {
                $shift = new AgentShift($agent, $dow, $shiftStart, $shiftEnd);
                $this->em->persist($shift);
            }
        }
        $this->em->flush();

        // Create a single ticket — all agents have load=0, today_count=0
        // Tiebreaker should pick lowest id
        $ticket = new Ticket();
        $ticket->setStatus(TicketStatus::NEW->value);
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        /** @var AutoAssignTicketUseCase $useCase */
        $useCase = static::getContainer()->get(AutoAssignTicketUseCase::class);
        $result = $useCase->execute($ticketId);

        // The lowest-id agent should be selected
        $this->assertTrue($result['assigned']);
        $lowestAgentId = min(array_map(fn(Agent $a) => (int) $a->getId(), $agents));
        $this->assertEquals($lowestAgentId, $result['agent_id'], 'Lowest id agent should be selected when loads and today counts are equal');

        // Now create another ticket — agent with lowest id now has load=1, others load=0
        // The second ticket should go to the NEXT lowest id
        $ticket2 = new Ticket();
        $ticket2->setStatus(TicketStatus::NEW->value);
        $this->em->persist($ticket2);
        $this->em->flush();

        $result2 = $useCase->execute((int) $ticket2->getId());
        $this->assertTrue($result2['assigned']);

        // Should be the second-lowest agent (first is now at capacity... well, not at max=10)
        // Both agent2 and agent3 have load=0, so lowest id (agent2) should win
        $sortedIds = array_map(fn(Agent $a) => (int) $a->getId(), $agents);
        sort($sortedIds);
        // agent[0] (lowest) has load 1, agent[1] and agent[2] have load 0
        // among agent[1] and agent[2] (both load 0), agent[1] (lower id) wins
        $this->assertEquals($sortedIds[1], $result2['agent_id'], 'Second ticket should go to second-lowest agent');
    }

    /**
     * Load-based tiebreaker: agent with fewer active tickets wins.
     */
    public function testLoadBasedTiebreaker(): void
    {
        // Create 2 agents
        $agentLow = new Agent('Low Load');
        $agentLow->setMaxActiveTickets(10);
        $agentLow->setTimezone('UTC');
        $this->em->persist($agentLow);

        $agentHigh = new Agent('High Load');
        $agentHigh->setMaxActiveTickets(10);
        $agentHigh->setTimezone('UTC');
        $this->em->persist($agentHigh);
        $this->em->flush();

        // Give both agents 24/7 shifts (all days of week)
        $shiftStart = new \DateTimeImmutable('00:00:00');
        $shiftEnd = new \DateTimeImmutable('23:59:59');
        foreach ([$agentLow, $agentHigh] as $agent) {
            for ($dow = 1; $dow <= 7; $dow++) {
                $shift = new AgentShift($agent, $dow, $shiftStart, $shiftEnd);
                $this->em->persist($shift);
            }
        }
        $this->em->flush();

        // The agent with lowest id will have fewer tickets (when both at 0)
        // We assign tickets to the highest-id agent first to bias comparison
        // Actually — let's assign one ticket manually to agentHigh so it has more load.

        // Create a ticket pre-assigned to agentHigh to increase its load
        $preTicket = new Ticket();
        $preTicket->setStatus(TicketStatus::ACTIVE->value);
        $preTicket->setAssignedAgentId($agentHigh->getId());
        $this->em->persist($preTicket);
        $this->em->flush();

        // Create an unassigned ticket
        $ticket = new Ticket();
        $ticket->setStatus(TicketStatus::NEW->value);
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        // Run auto-assign
        /** @var AutoAssignTicketUseCase $useCase */
        $useCase = static::getContainer()->get(AutoAssignTicketUseCase::class);
        $result = $useCase->execute($ticketId);

        // Should pick agentLow (lower load)
        $this->assertTrue($result['assigned']);
        $this->assertEquals($agentLow->getId(), $result['agent_id'], 'Should pick agent with lower load');
    }

    /**
     * No eligible agents — ticket remains unassigned.
     */
    public function testNoEligibleAgents(): void
    {
        // Create an agent but with max_active_tickets = 0 (impossible via setter, so...)
        $agent = new Agent('Full Agent');
        $agent->setMaxActiveTickets(1);
        $agent->setTimezone('UTC');
        $this->em->persist($agent);
        $this->em->flush();

        // Give it 24/7 shifts (all days)
        $shiftStart = new \DateTimeImmutable('00:00:00');
        $shiftEnd = new \DateTimeImmutable('23:59:59');
        for ($dow = 1; $dow <= 7; $dow++) {
            $shift = new AgentShift($agent, $dow, $shiftStart, $shiftEnd);
            $this->em->persist($shift);
        }
        $this->em->flush();

        // Fill agent to capacity
        $fillTicket = new Ticket();
        $fillTicket->setStatus(TicketStatus::ACTIVE->value);
        $fillTicket->setAssignedAgentId($agent->getId());
        $this->em->persist($fillTicket);
        $this->em->flush();

        // Create unassigned ticket
        $ticket = new Ticket();
        $ticket->setStatus(TicketStatus::NEW->value);
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        // Run auto-assign
        /** @var AutoAssignTicketUseCase $useCase */
        $useCase = static::getContainer()->get(AutoAssignTicketUseCase::class);
        $result = $useCase->execute($ticketId);

        $this->assertFalse($result['assigned']);
        $this->assertNull($result['agent_id']);
        $this->assertContains($result['reason'], ['no_agents_available', 'all_agents_at_capacity']);

        // Verify no Assignment row created
        $count = (int) $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM assignments WHERE ticket_id = ?',
            [$ticketId],
        )->fetchOne();
        $this->assertEquals(0, $count, 'No Assignment row should be created when no eligible agents');
    }

    /**
     * Already assigned ticket — idempotent.
     */
    public function testAlreadyAssignedTicketIsIdempotent(): void
    {
        $agent = new Agent('Owner');
        $agent->setMaxActiveTickets(5);
        $agent->setTimezone('UTC');
        $this->em->persist($agent);
        $this->em->flush();

        $shift = new AgentShift($agent, (int) date('N'), new \DateTimeImmutable('00:00:00'), new \DateTimeImmutable('23:59:59'));
        $this->em->persist($shift);

        // Create an already-assigned ticket
        $ticket = new Ticket();
        $ticket->setStatus(TicketStatus::ACTIVE->value);
        $ticket->setAssignedAgentId($agent->getId());
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        // Run auto-assign
        /** @var AutoAssignTicketUseCase $useCase */
        $useCase = static::getContainer()->get(AutoAssignTicketUseCase::class);
        $result = $useCase->execute($ticketId);

        $this->assertFalse($result['assigned']);
        $this->assertEquals($agent->getId(), $result['agent_id']);
        $this->assertEquals('already_assigned', $result['reason']);

        // Verify no new Assignment row was created
        $assignmentCount = (int) $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM assignments WHERE ticket_id = ?',
            [$ticketId],
        )->fetchOne();
        $this->assertEquals(0, $assignmentCount, 'No new Assignment row for already-assigned ticket');
    }

    /**
     * Ticket not found returns error.
     */
    public function testTicketNotFound(): void
    {
        /** @var AutoAssignTicketUseCase $useCase */
        $useCase = static::getContainer()->get(AutoAssignTicketUseCase::class);
        $result = $useCase->execute(99999);

        $this->assertFalse($result['assigned']);
        $this->assertNull($result['agent_id']);
        $this->assertEquals('ticket_not_found', $result['reason']);
    }
}
