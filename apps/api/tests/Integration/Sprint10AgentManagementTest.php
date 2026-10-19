<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Asignacion\UseCase\AsignarTicketAutomaticamenteUseCase;
use App\Agente\Agente;
use App\Disponibilidad\Turno;
use App\Asignacion\Asignacion;
use App\Compartido\Outbox\OutboxEvent;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for Sprint 10 Agent Management — PR3, plus the
 * ticket-assign-dropdown-status assign-time Activo guard (Phase 3).
 *
 * Covers:
 *   - Full auto-assignment flow (agent → shift → ticket → assign)
 *   - Audit trail (Asignacion row created with correct type)
 *   - Outbox event queued
 *   - Deterministic tiebreaker behavior
 *   - Assign-time guard: 409 AGENT_NOT_ACTIVE for every role, no bypass
 */
class Sprint10AgentManagementTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?EntityManagerInterface $em = null;
    private ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
        $this->client = null;
    }

    // ────────────────────────────────────────────────────────
    //  Helpers (assign-time guard tests)
    // ────────────────────────────────────────────────────────

    private function createUser(
        string $email,
        array $roles = ['ROLE_AGENT'],
        ?Agente $agent = null,
    ): Usuario {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setIsActive(true);

        if (null !== $agent) {
            $agent->setUsuario($user);
            $this->em->persist($agent);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function login(string $email): string
    {
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => $email,
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        return $data['access_token'];
    }

    private function authHeaders(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function postAssign(int $ticketId, int $agentId, string $assignedBy, string $token): void
    {
        $this->client->request('POST', '/api/tickets/' . $ticketId . '/assign', [], [], \array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            $this->authHeaders($token),
        ), \json_encode([
            'agentId' => $agentId,
            'assignedBy' => $assignedBy,
        ]));
    }

    /**
     * Full flow: Create agent with shift → Post ticket → Auto-assign → Verify.
     *
     * T-14.1: Assert ticket.owner_agent_id = agent.id
     * T-14.2: Assert Asignacion row created with type=AUTO_ASSIGN
     * T-14.3: Assert outbox event queued
     */
    public function testFullAutoAssignmentFlow(): void
    {
        // 1. Create an agent
        $agent = new Agente('Test Agent');
        $agent->setMaxTicketsActivos(5);
        $agent->setZonaHoraria('UTC');
        $this->em->persist($agent);
        $this->em->flush();

        $agentId = $agent->getId();

        // 2. Create 24/7 shifts for the agent (all days of week)
        $shiftStart = new \DateTimeImmutable('00:00:00');
        $shiftEnd = new \DateTimeImmutable('23:59:59');
        for ($dow = 1; $dow <= 7; $dow++) {
            $shift = new Turno($agent, $dow, $shiftStart, $shiftEnd);
            $this->em->persist($shift);
        }
        $this->em->flush();

        // 3. Create an unassigned ticket
        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $ticket->setCreadoAt(new \DateTimeImmutable());
        $ticket->setActualizadoAt(new \DateTimeImmutable());
        $this->em->persist($ticket);
        $this->em->flush();

        $ticketId = (int) $ticket->getId();
        $this->assertNotNull($ticketId);

        // 4. Run auto-assignment
        /** @var AsignarTicketAutomaticamenteUseCase $useCase */
        $useCase = static::getContainer()->get(AsignarTicketAutomaticamenteUseCase::class);
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
        $this->assertEquals(EstadoDeTicket::ACTIVO->value, $savedTicket['status'], 'Ticket status should be ACTIVE after assignment');

        // 7. Assert Asignacion row created with type=AUTO_ASSIGN
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
            $agent = new Agente("Agent $i");
            $agent->setMaxTicketsActivos(10);
            $agent->setZonaHoraria('UTC');
            $this->em->persist($agent);
            $this->em->flush();
            $agents[] = $agent;
        }

        // All agents get 24/7 shifts (all days of week)
        $shiftStart = new \DateTimeImmutable('00:00:00');
        $shiftEnd = new \DateTimeImmutable('23:59:59');
        foreach ($agents as $agent) {
            for ($dow = 1; $dow <= 7; $dow++) {
                $shift = new Turno($agent, $dow, $shiftStart, $shiftEnd);
                $this->em->persist($shift);
            }
        }
        $this->em->flush();

        // Create a single ticket — all agents have load=0, today_count=0
        // Tiebreaker should pick lowest id
        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        /** @var AsignarTicketAutomaticamenteUseCase $useCase */
        $useCase = static::getContainer()->get(AsignarTicketAutomaticamenteUseCase::class);
        $result = $useCase->execute($ticketId);

        // The lowest-id agent should be selected
        $this->assertTrue($result['assigned']);
        $lowestAgentId = min(array_map(fn(Agente $a) => (int) $a->getId(), $agents));
        $this->assertEquals($lowestAgentId, $result['agent_id'], 'Lowest id agent should be selected when loads and today counts are equal');

        // Now create another ticket — agent with lowest id now has load=1, others load=0
        // The second ticket should go to the NEXT lowest id
        $ticket2 = new Ticket();
        $ticket2->setEstado(EstadoDeTicket::NUEVO->value);
        $this->em->persist($ticket2);
        $this->em->flush();

        $result2 = $useCase->execute((int) $ticket2->getId());
        $this->assertTrue($result2['assigned']);

        // Should be the second-lowest agent (first is now at capacity... well, not at max=10)
        // Both agent2 and agent3 have load=0, so lowest id (agent2) should win
        $sortedIds = array_map(fn(Agente $a) => (int) $a->getId(), $agents);
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
        $agentLow = new Agente('Low Load');
        $agentLow->setMaxTicketsActivos(10);
        $agentLow->setZonaHoraria('UTC');
        $this->em->persist($agentLow);

        $agentHigh = new Agente('High Load');
        $agentHigh->setMaxTicketsActivos(10);
        $agentHigh->setZonaHoraria('UTC');
        $this->em->persist($agentHigh);
        $this->em->flush();

        // Give both agents 24/7 shifts (all days of week)
        $shiftStart = new \DateTimeImmutable('00:00:00');
        $shiftEnd = new \DateTimeImmutable('23:59:59');
        foreach ([$agentLow, $agentHigh] as $agent) {
            for ($dow = 1; $dow <= 7; $dow++) {
                $shift = new Turno($agent, $dow, $shiftStart, $shiftEnd);
                $this->em->persist($shift);
            }
        }
        $this->em->flush();

        // The agent with lowest id will have fewer tickets (when both at 0)
        // We assign tickets to the highest-id agent first to bias comparison
        // Actually — let's assign one ticket manually to agentHigh so it has more load.

        // Create a ticket pre-assigned to agentHigh to increase its load
        $preTicket = new Ticket();
        $preTicket->setEstado(EstadoDeTicket::ACTIVO->value);
        $preTicket->setAgenteAsignadoId($agentHigh->getId());
        $this->em->persist($preTicket);
        $this->em->flush();

        // Create an unassigned ticket
        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        // Run auto-assign
        /** @var AsignarTicketAutomaticamenteUseCase $useCase */
        $useCase = static::getContainer()->get(AsignarTicketAutomaticamenteUseCase::class);
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
        $agent = new Agente('Full Agent');
        $agent->setMaxTicketsActivos(1);
        $agent->setZonaHoraria('UTC');
        $this->em->persist($agent);
        $this->em->flush();

        // Give it 24/7 shifts (all days)
        $shiftStart = new \DateTimeImmutable('00:00:00');
        $shiftEnd = new \DateTimeImmutable('23:59:59');
        for ($dow = 1; $dow <= 7; $dow++) {
            $shift = new Turno($agent, $dow, $shiftStart, $shiftEnd);
            $this->em->persist($shift);
        }
        $this->em->flush();

        // Fill agent to capacity
        $fillTicket = new Ticket();
        $fillTicket->setEstado(EstadoDeTicket::ACTIVO->value);
        $fillTicket->setAgenteAsignadoId($agent->getId());
        $this->em->persist($fillTicket);
        $this->em->flush();

        // Create unassigned ticket
        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        // Run auto-assign
        /** @var AsignarTicketAutomaticamenteUseCase $useCase */
        $useCase = static::getContainer()->get(AsignarTicketAutomaticamenteUseCase::class);
        $result = $useCase->execute($ticketId);

        $this->assertFalse($result['assigned']);
        $this->assertNull($result['agent_id']);
        $this->assertContains($result['reason'], ['no_agents_available', 'all_agents_at_capacity']);

        // Verify no Asignacion row created
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
        $agent = new Agente('Owner');
        $agent->setMaxTicketsActivos(5);
        $agent->setZonaHoraria('UTC');
        $this->em->persist($agent);
        $this->em->flush();

        $shift = new Turno($agent, (int) date('N'), new \DateTimeImmutable('00:00:00'), new \DateTimeImmutable('23:59:59'));
        $this->em->persist($shift);

        // Create an already-assigned ticket
        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::ACTIVO->value);
        $ticket->setAgenteAsignadoId($agent->getId());
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        // Run auto-assign
        /** @var AsignarTicketAutomaticamenteUseCase $useCase */
        $useCase = static::getContainer()->get(AsignarTicketAutomaticamenteUseCase::class);
        $result = $useCase->execute($ticketId);

        $this->assertFalse($result['assigned']);
        $this->assertEquals($agent->getId(), $result['agent_id']);
        $this->assertEquals('already_assigned', $result['reason']);

        // Verify no new Asignacion row was created
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
        /** @var AsignarTicketAutomaticamenteUseCase $useCase */
        $useCase = static::getContainer()->get(AsignarTicketAutomaticamenteUseCase::class);
        $result = $useCase->execute(99999);

        $this->assertFalse($result['assigned']);
        $this->assertNull($result['agent_id']);
        $this->assertEquals('ticket_not_found', $result['reason']);
    }

    // ────────────────────────────────────────────────────────
    //  Assign-time Activo guard (ticket-assign-dropdown-status, Phase 3)
    //
    //  HARD BLOCK for every role — no bypass. Uses disponibilidadManual for
    //  deterministic Activo/Inactivo state (not weekday-dependent).
    // ────────────────────────────────────────────────────────

    /** ROLE_AGENT: blocked from assigning to an Inactivo agent, even on own ticket. */
    public function testAssignRejectsInactiveAgentForRoleAgent(): void
    {
        $ownerAgent = new Agente('Owner Agent');
        $ownerAgent->setMaxTicketsActivos(5);
        $this->em->persist($ownerAgent);
        $this->em->flush();
        $this->createUser('owner@test.local', ['ROLE_AGENT'], $ownerAgent);
        $token = $this->login('owner@test.local');

        $inactiveAgent = new Agente('Inactivo Agent');
        $inactiveAgent->setDisponibilidadManual(false);
        $this->em->persist($inactiveAgent);
        $this->em->flush();

        // Ticket already owned by the requesting agent (voter allows ASSIGN)
        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::ACTIVO->value);
        $ticket->setAgenteAsignadoId($ownerAgent->getId());
        $this->em->persist($ticket);
        $this->em->flush();

        $this->postAssign((int) $ticket->getId(), (int) $inactiveAgent->getId(), 'owner@test.local', $token);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('AGENT_NOT_ACTIVE', $data['type']);
    }

    /** ROLE_SUPERVISOR: blocked from assigning to an Inactivo agent — no role bypass. */
    public function testAssignRejectsInactiveAgentForRoleSupervisor(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $inactiveAgent = new Agente('Inactivo Agent');
        $inactiveAgent->setDisponibilidadManual(false);
        $this->em->persist($inactiveAgent);
        $this->em->flush();

        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $this->em->persist($ticket);
        $this->em->flush();

        $this->postAssign((int) $ticket->getId(), (int) $inactiveAgent->getId(), 'supervisor@test.local', $token);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('AGENT_NOT_ACTIVE', $data['type']);
    }

    /** ROLE_ADMIN: blocked from assigning to an Inactivo agent — no role bypass. */
    public function testAssignRejectsInactiveAgentForRoleAdmin(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $inactiveAgent = new Agente('Inactivo Agent');
        $inactiveAgent->setDisponibilidadManual(false);
        $this->em->persist($inactiveAgent);
        $this->em->flush();

        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $this->em->persist($ticket);
        $this->em->flush();

        $this->postAssign((int) $ticket->getId(), (int) $inactiveAgent->getId(), 'admin@test.local', $token);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('AGENT_NOT_ACTIVE', $data['type']);
    }

    /** Asignacion succeeds when the target agent is Activo. */
    public function testAssignSucceedsForActiveAgent(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $activeAgent = new Agente('Activo Agent');
        $activeAgent->setDisponibilidadManual(true);
        $this->em->persist($activeAgent);
        $this->em->flush();

        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $this->em->persist($ticket);
        $this->em->flush();

        $this->postAssign((int) $ticket->getId(), (int) $activeAgent->getId(), 'supervisor@test.local', $token);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($activeAgent->getId(), $data['agent_id']);
    }

    /** A 409 rejection must write no Asignacion row and emit no outbox event. */
    public function testAssignRejectionWritesNoAssignmentRowOrOutboxEvent(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $inactiveAgent = new Agente('Inactivo Agent');
        $inactiveAgent->setDisponibilidadManual(false);
        $this->em->persist($inactiveAgent);
        $this->em->flush();

        $ticket = new Ticket();
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $this->em->persist($ticket);
        $this->em->flush();
        $ticketId = (int) $ticket->getId();

        $this->postAssign($ticketId, (int) $inactiveAgent->getId(), 'admin@test.local', $token);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        $assignmentCount = (int) $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM assignments WHERE ticket_id = ?',
            [$ticketId],
        )->fetchOne();
        self::assertSame(0, $assignmentCount, 'No Assignment row should be created on rejection');

        $outboxCount = (int) $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM outbox_events WHERE aggregate_id = ?',
            [$ticketId],
        )->fetchOne();
        self::assertSame(0, $outboxCount, 'No outbox event should be queued on rejection');
    }
}
