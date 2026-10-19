<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Automatizacion\EjecutorDeReglas;
use App\Conversacion\UseCase\HandleOutboundMessageUseCase;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Automatizacion\ContextoDeRegla;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Compartido\StateConflictException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * spec.md "reply-auto-assignment" domain — Reply-Triggered Ownership Claim,
 * Resolved/Closed Ticket Ownership Guard, and No Event Leakage requirements.
 *
 * First-ever `agentId > 0` integration coverage for
 * `HandleOutboundMessageUseCase::processOutboundMessage()` — this path
 * shipped live with ZERO tests before this change.
 */
class AgentReplyAutoClaimTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM processed_events');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM rule_execution_logs');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    // ============================================
    // Reply-Triggered Ownership Claim
    // ============================================

    public function testReplyClaimsAnUnassignedTicket(): void
    {
        $agentId = $this->seedAgent('Agent Five');
        $ticketId = $this->seedTicket(EstadoDeTicket::ACTIVO->value, null);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage($ticketId, 'Hi, how can I help?', $agentId);

        self::assertSame('ok', $result['status']);

        $this->em->clear();
        $conn = $this->em->getConnection();

        $owner = (int) $conn->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]);
        self::assertSame($agentId, $owner);

        $assignments = $conn->fetchAllAssociative('SELECT type, agent_id, previous_agent_id FROM assignments WHERE ticket_id = ?', [$ticketId]);
        self::assertCount(1, $assignments, 'Exactly one audit row must be created for claiming an unassigned ticket');
        self::assertSame('AUTO_ASSIGN', $assignments[0]['type']);
        self::assertSame($agentId, (int) $assignments[0]['agent_id']);
        self::assertNull($assignments[0]['previous_agent_id'], 'Claiming an unassigned ticket has no previous owner');

        $lastReadAt = $conn->fetchOne('SELECT last_read_at FROM tickets WHERE id = ?', [$ticketId]);
        self::assertNotNull($lastReadAt, 'Becoming the owner must mark the ticket as read');
    }

    public function testReplyReassignsATicketOwnedByADifferentAgent(): void
    {
        $agentA = $this->seedAgent('Agent A');
        $agentB = $this->seedAgent('Agent B');
        $ticketId = $this->seedTicket(EstadoDeTicket::ACTIVO->value, $agentA);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage($ticketId, 'Taking over this one', $agentB);

        self::assertSame('ok', $result['status']);

        $this->em->clear();
        $conn = $this->em->getConnection();

        $owner = (int) $conn->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]);
        self::assertSame($agentB, $owner);

        $assignments = $conn->fetchAllAssociative('SELECT type, agent_id, previous_agent_id FROM assignments WHERE ticket_id = ?', [$ticketId]);
        self::assertCount(1, $assignments);
        self::assertSame('REASSIGN', $assignments[0]['type']);
        self::assertSame($agentB, (int) $assignments[0]['agent_id']);
        self::assertSame($agentA, (int) $assignments[0]['previous_agent_id'], 'REASSIGN must record the previous owner');
    }

    public function testReplyByCurrentOwnerCausesNoReassignment(): void
    {
        $agentA = $this->seedAgent('Agent A');
        $ticketId = $this->seedTicket(EstadoDeTicket::ACTIVO->value, $agentA);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage($ticketId, 'Following up', $agentA);

        self::assertSame('ok', $result['status']);

        $this->em->clear();
        $conn = $this->em->getConnection();

        $owner = (int) $conn->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]);
        self::assertSame($agentA, $owner);

        $assignmentCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]);
        self::assertSame(0, $assignmentCount, 'The current owner replying must not create a new audit row');
    }

    // ============================================
    // No Event Leakage from Failed/Retried Attempts
    // ============================================

    /**
     * design.md D6: a rolled-back attempt must not leak its collected events
     * (TicketUpdatedEvent / InboxUpdatedEvent) into a later successful call.
     *
     * NOTE on deviation from design.md's literal testing-strategy wording
     * ("bump ticket.version from a second connection"): that specific race
     * is no longer reproducible now that D1's pessimistic lock is the
     * transaction's first statement — any external writer now BLOCKS
     * instead of racing (proven by AgentReplyAutoClaimConcurrencyTest), so
     * an OptimisticLockException can no longer occur mid-flight for this
     * method in normal operation. This test instead forces the abort via
     * the NEW `\Throwable` branch (design.md D6's second clear-on-abort
     * path) using a single fault-injecting test double for
     * `EjecutorDeReglas` — the LAST collaborator called, after
     * `registrarCambioDeResponsable()`, the Message, and the ProcessedEvent have
     * already been flushed within the same still-open transaction. Every
     * other collaborator (EntityManager, EventCollector, ResponsabilidadDeTicketService,
     * repositories) stays real.
     */
    public function testEventCollectorDoesNotLeakEventsFromARolledBackAttempt(): void
    {
        $agentId = $this->seedAgent('Agent Leak Proof');
        $ticketId = $this->seedTicket(EstadoDeTicket::ACTIVO->value, null);

        $explodingRuleExecutor = new class(
            static::getContainer()->get(\App\Automatizacion\Repository\RegistroDeReglaRepository::class),
            static::getContainer()->get(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class),
        ) extends EjecutorDeReglas {
            public function execute(ContextoDeRegla $context): void
            {
                throw new \RuntimeException('Simulated failure after ownership change was collected');
            }
        };
        static::getContainer()->set(EjecutorDeReglas::class, $explodingRuleExecutor);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);

        $threw = false;
        try {
            $useCase->processOutboundMessage($ticketId, 'this attempt must roll back', $agentId);
        } catch (\RuntimeException $e) {
            $threw = true;
            self::assertSame('Simulated failure after ownership change was collected', $e->getMessage());
        }
        self::assertTrue($threw, 'The fault-injected RuleExecutorService must cause the whole attempt to abort');

        // The WHOLE transaction (assignment + message + processed event)
        // must have rolled back — nothing from the failed attempt persists.
        $this->em->clear();
        $conn = $this->em->getConnection();

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM outbox_events WHERE aggregate_id = ?', [$ticketId]));
        self::assertNull($conn->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]));

        $eventCollector = static::getContainer()->get(\App\Compartido\EventCollector::class);
        self::assertSame(
            [],
            $eventCollector->getEvents(),
            'EventCollector must be cleared on the \Throwable abort path — no leaked TicketUpdatedEvent/InboxUpdatedEvent',
        );

        // Symfony's test container refuses to `set()` an already-initialized
        // service a second time — reboot the kernel to get a fresh container
        // with the REAL EjecutorDeReglas (untouched) via normal DI, then
        // retry against the SAME DB rows (MySQL data survives the reboot).
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage($ticketId, 'retry after the rollback', $agentId);
        self::assertSame('ok', $result['status']);

        $this->em->clear();
        $conn = $this->em->getConnection();

        $assignmentCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]);
        self::assertSame(1, $assignmentCount, 'The retry must produce exactly ONE assignment row — no duplicate from the failed attempt');

        $ticketUpdatedCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM outbox_events WHERE aggregate_id = ? AND event_type = ?",
            [$ticketId, \App\Ticket\TicketUpdatedEvent::class],
        );
        self::assertSame(1, $ticketUpdatedCount, 'Exactly one TicketUpdatedEvent must reach the outbox — no phantom duplicate from the rolled-back attempt');

        $seqs = $conn->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE aggregate_id = ? AND event_type = ?',
            [$ticketId, \App\Ticket\TicketUpdatedEvent::class],
        );
        $decodedSeqs = array_map(static fn (string $p) => json_decode($p, true)['seq'], $seqs);
        self::assertSame(array_unique($decodedSeqs), array_values($decodedSeqs), 'No duplicate seq values from the leaked/rolled-back attempt');
    }

    // ============================================
    // Resolved/Closed Ticket Ownership Guard
    // ============================================

    public function testSupervisorReplyToResolvedTicketIsDeliveredWithoutReassignment(): void
    {
        $owner = $this->seedAgent('Owner Agent');
        $supervisor = $this->seedAgent('Supervisor Agent');
        $ticketId = $this->seedTicket(EstadoDeTicket::RESUELTO->value, $owner);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage(
            ticketId: $ticketId,
            content: 'Closing remarks from a supervisor',
            agentId: $supervisor,
            senderIsPrivileged: true,
        );

        self::assertSame('ok', $result['status']);
        self::assertNotNull($result['message_id']);

        $this->em->clear();
        $conn = $this->em->getConnection();

        self::assertSame($owner, (int) $conn->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]));
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM messages m INNER JOIN tickets t ON t.conversation_id = m.conversation_id WHERE t.id = ?',
            [$ticketId],
        ));
    }

    public function testOwningAgentReplyToOwnResolvedTicketIsDeliveredWithoutReassignment(): void
    {
        $owner = $this->seedAgent('Owner Agent');
        $ticketId = $this->seedTicket(EstadoDeTicket::RESUELTO->value, $owner);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage(
            ticketId: $ticketId,
            content: 'One more thing',
            agentId: $owner,
            senderIsPrivileged: false,
        );

        self::assertSame('ok', $result['status']);

        $this->em->clear();
        $conn = $this->em->getConnection();

        self::assertSame($owner, (int) $conn->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]));
    }

    public function testNonOwningAgentReplyToResolvedTicketIsBlocked(): void
    {
        $owner = $this->seedAgent('Owner Agent');
        $intruder = $this->seedAgent('Non-Owning Agent');
        $ticketId = $this->seedTicket(EstadoDeTicket::RESUELTO->value, $owner);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);

        $this->expectException(StateConflictException::class);

        try {
            $useCase->processOutboundMessage(
                ticketId: $ticketId,
                content: 'I am taking this ticket',
                agentId: $intruder,
                senderIsPrivileged: false,
            );
        } finally {
            $this->em->clear();
            $conn = $this->em->getConnection();

            self::assertSame($owner, (int) $conn->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]), 'Ownership must remain unchanged');
            self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]), 'No assignment row on a blocked reply');
            self::assertSame(0, (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM messages m INNER JOIN tickets t ON t.conversation_id = m.conversation_id WHERE t.id = ?',
                [$ticketId],
            ), 'No message row may be persisted — the whole transaction must roll back');
            self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM outbox_events WHERE aggregate_id = ?', [$ticketId]), 'No outbox row on a blocked reply');
        }
    }

    /** Triangulation: same guard, CLOSED status instead of RESOLVED. */
    public function testNonOwningAgentReplyToClosedTicketIsBlocked(): void
    {
        $owner = $this->seedAgent('Owner Agent');
        $intruder = $this->seedAgent('Non-Owning Agent');
        $ticketId = $this->seedTicket(EstadoDeTicket::CERRADO->value, $owner);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);

        $this->expectException(StateConflictException::class);
        $useCase->processOutboundMessage(
            ticketId: $ticketId,
            content: 'I am taking this ticket',
            agentId: $intruder,
            senderIsPrivileged: false,
        );
    }

    /** Triangulation: agentId <= 0 (system caller) must never trip the guard, even on a resolved ticket. */
    public function testSystemCallerWithZeroAgentIdIsNeverBlockedByTheGuard(): void
    {
        $owner = $this->seedAgent('Owner Agent');
        $ticketId = $this->seedTicket(EstadoDeTicket::RESUELTO->value, $owner);

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage($ticketId, 'system message', 0);

        self::assertSame('ok', $result['status']);
    }

    // ============================================
    // Helpers
    // ============================================

    private function seedAgent(string $name): int
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO agents (name, active, is_available, max_active_tickets, timezone, created_at, updated_at) VALUES (?, 1, 1, 10, \'UTC\', NOW(), NOW())',
            [$name],
        );

        return (int) $conn->lastInsertId();
    }

    private function seedTicket(string $status, ?int $assignedAgentId): int
    {
        $contact = new Contacto();
        $contact->setTelefono('+549' . random_int(100000000, 999999999));
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado($status);
        $ticket->setAgenteAsignadoId($assignedAgentId);
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($ticket);
        $this->em->flush();

        $ticketId = $ticket->getId();
        \assert($ticketId !== null);

        return (int) $ticketId;
    }
}
