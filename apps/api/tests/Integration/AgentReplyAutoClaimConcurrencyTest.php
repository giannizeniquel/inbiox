<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Conversacion\UseCase\HandleOutboundMessageUseCase;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Compartido\ConcurrencyException;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two load-bearing concurrency proofs for design.md D1/D2:
 *
 * 1. Lock-blocking proof: a SECOND real DBAL connection holds
 *    `SELECT ... FOR UPDATE` on the ticket row; the main connection sets
 *    `innodb_lock_wait_timeout = 1` and calls the real use case — it must
 *    actually block/fail on lock wait, proving `findByIdForUpdate()`
 *    genuinely acquires a row lock (not just optimistic versioning).
 *
 * 2. Last-commit-wins determinism proof: `pcntl_fork` with TWO REAL CHILD
 *    PROCESSES, each booting its own kernel and driving the ACTUAL use case
 *    end-to-end. Does NOT reuse/extend `Sprint10ConcurrencyTest`'s pcntl
 *    test — that one degrades to raw SQL and proves nothing about this use
 *    case. Skips gracefully if `pcntl` is unavailable (confirmed absent in
 *    this container as of this change); the lock-blocking proof above then
 *    carries the concurrency burden alone.
 */
class AgentReplyAutoClaimConcurrencyTest extends KernelTestCase
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

    /**
     * Proof 1a — the DISCRIMINATING repository-level proof.
     *
     * A naive "call the whole use case and assert it eventually throws" test
     * does NOT discriminate old vs. new code here: `$ticket->markUpdated()`
     * unconditionally writes the tickets row at the end of every successful
     * pass, and MySQL's FK check on the `assignments.ticket_id` insert also
     * implicitly locks the parent row — so the PRE-FIX code already blocks
     * and throws too, just later (at write-time, not read-time), giving a
     * false-positive RED. Verified empirically while writing this test.
     *
     * This test isolates the ONE thing that must change: the plain,
     * non-locking `findById()` read (still used elsewhere, e.g.
     * `AssignTicketUseCase`) never contends for the row lock at all, while
     * the NEW `findByIdForUpdate()` (design.md D1) blocks immediately and
     * fails on lock-wait-timeout under identical contention.
     */
    public function testFindByIdForUpdateBlocksOnRowLockWhereasPlainFindByIdNever(): void
    {
        $ticketId = $this->seedTicket(EstadoDeTicket::ACTIVO->value, null);

        $conn2 = DriverManager::getConnection($this->em->getConnection()->getParams());
        $conn2->beginTransaction();
        $conn2->executeQuery('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [$ticketId])->fetchAllAssociative();

        $this->em->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        /** @var \App\Ticket\Repository\TicketRepository $ticketRepo */
        $ticketRepo = static::getContainer()->get(\App\Ticket\Repository\TicketRepository::class);

        try {
            // Baseline: the OLD, still-used-elsewhere plain read never contends
            // for the row lock — proves the pre-fix path had zero locking.
            $plain = $ticketRepo->findById($ticketId);
            self::assertNotNull($plain, 'Plain findById() must succeed immediately — it never acquires a row lock');

            // The NEW locking finder must genuinely block and fail on lock-wait
            // timeout under the exact same contention — proving a real
            // PESSIMISTIC_WRITE row lock is acquired, not just optimistic
            // versioning. PESSIMISTIC_WRITE requires an explicit open
            // transaction (Doctrine enforces this at the ORM level).
            $this->em->getConnection()->beginTransaction();
            try {
                $ticketRepo->findByIdForUpdate($ticketId);
                self::fail(
                    'Expected findByIdForUpdate() to block on the second connection\'s FOR UPDATE lock ' .
                    'and then fail on lock-wait-timeout.',
                );
            } catch (\Throwable $e) {
                self::assertStringContainsStringIgnoringCase(
                    'lock wait timeout',
                    $e->getMessage(),
                    'findByIdForUpdate() must fail specifically on a MySQL lock-wait-timeout, proving it ' .
                    'genuinely contended for the row lock (not some unrelated failure).',
                );
            } finally {
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->getConnection()->rollBack();
                }
            }
        } finally {
            $conn2->rollBack();
            $conn2->close();
        }
    }

    /**
     * Proof 1b — end-to-end safety net (NOT a pre/post-fix discriminator —
     * see the docblock above). Whatever statement first contends for the
     * lock, the whole reply MUST roll back cleanly under contention: no
     * phantom message, no phantom assignment row, and the failure must
     * surface as a real DBAL exception rather than being silently absorbed
     * into the optimistic-lock retry budget.
     */
    public function testConcurrentReplyRollsBackCleanlyUnderLockContention(): void
    {
        $agentId = $this->seedAgent('Lock Proof Agent');
        $ticketId = $this->seedTicket(EstadoDeTicket::ACTIVO->value, null);

        $conn2 = DriverManager::getConnection($this->em->getConnection()->getParams());
        $conn2->beginTransaction();
        $conn2->executeQuery('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [$ticketId])->fetchAllAssociative();

        $this->em->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        /** @var HandleOutboundMessageUseCase $useCase */
        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);

        $threw = false;
        try {
            $useCase->processOutboundMessage($ticketId, 'blocked reply', $agentId);
        } catch (\Throwable $e) {
            $threw = true;
            self::assertNotInstanceOf(
                ConcurrencyException::class,
                $e,
                'A lock-wait timeout must surface as a DBAL exception directly — it must NOT be caught ' .
                'as an OptimisticLockException and consume the retry budget.',
            );
        } finally {
            $conn2->rollBack();
            $conn2->close();
        }

        self::assertTrue($threw, 'Expected the reply to fail while the second connection holds the row lock.');

        $this->em->clear();
        $conn = $this->em->getConnection();

        $messageCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM messages m
             INNER JOIN tickets t ON t.conversation_id = m.conversation_id
             WHERE t.id = ?',
            [$ticketId],
        );
        self::assertSame(0, $messageCount, 'No message row may be written when the transaction rolled back on lock-wait-timeout');

        $assignmentCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]);
        self::assertSame(0, $assignmentCount, 'No assignment row may be written when the transaction rolled back on lock-wait-timeout');
    }

    /**
     * Proof 2 — last-commit-wins determinism. Two real child processes, each
     * booting its own kernel, each driving the REAL use case for a different
     * agent replying to the same unassigned ticket.
     */
    public function testConcurrentRepliesFromDifferentAgentsProduceDeterministicLastCommitWinsOwnership(): void
    {
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped(
                'pcntl extension is not available in this container. Per design.md, the lock-blocking proof ' .
                '(testConcurrentReplyBlocksOnRowLockInsteadOfReadingStaleData) carries the full concurrency-proof ' .
                'burden alone when pcntl is unavailable.',
            );
        }

        $agentA = $this->seedAgent('Agent A');
        $agentB = $this->seedAgent('Agent B');
        $ticketId = $this->seedTicket(EstadoDeTicket::ACTIVO->value, null);

        // Release the parent's DB connection before forking — forked children
        // must never share a live socket/file descriptor with the parent.
        $this->em->getConnection()->close();

        $tmpFile = tempnam(sys_get_temp_dir(), 'lastcommit_');
        file_put_contents($tmpFile, '');

        $children = [];
        foreach ([$agentA, $agentB] as $agentId) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->markTestSkipped('pcntl_fork failed');
                return;
            }

            if ($pid === 0) {
                // Child process: its own kernel, its own fresh DB connection.
                try {
                    self::bootKernel();
                    $container = static::getContainer();
                    /** @var EntityManagerInterface $childEm */
                    $childEm = $container->get(EntityManagerInterface::class);
                    $childEm->getConnection()->close(); // force a brand-new connection in this process

                    /** @var HandleOutboundMessageUseCase $useCase */
                    $useCase = $container->get(HandleOutboundMessageUseCase::class);
                    $useCase->processOutboundMessage($ticketId, "reply from agent $agentId", $agentId);

                    file_put_contents($tmpFile, "ok|$agentId\n", FILE_APPEND);
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents($tmpFile, 'error|' . $agentId . '|' . \get_class($e) . ':' . $e->getMessage() . "\n", FILE_APPEND);
                    exit(1);
                }
            }

            $children[] = $pid;
        }

        foreach ($children as $childPid) {
            pcntl_waitpid($childPid, $status);
        }

        $lines = file($tmpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        unlink($tmpFile);

        self::assertCount(2, $lines, 'Both child processes must report a result');
        foreach ($lines as $line) {
            self::assertStringStartsWith('ok|', $line, "Child process must not fail or throw ConcurrencyException: $line");
        }

        // Parent needs a fresh connection to read what the children committed.
        $this->em->getConnection()->close();
        $this->em->clear();
        $conn = $this->em->getConnection();

        $ticketRow = $conn->fetchAssociative('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]);
        self::assertNotFalse($ticketRow);
        $finalOwner = (int) $ticketRow['assigned_agent_id'];
        self::assertContains($finalOwner, [$agentA, $agentB], 'Exactly one of the two replying agents must end up owning the ticket');

        $assignments = $conn->fetchAllAssociative(
            'SELECT agent_id, previous_agent_id FROM assignments WHERE ticket_id = ? ORDER BY id ASC',
            [$ticketId],
        );
        self::assertCount(2, $assignments, 'Both committed attempts must each produce exactly one audit row — no phantom/duplicate rows');
        self::assertNull($assignments[0]['previous_agent_id'], 'First committed assignment claims an unassigned ticket');
        self::assertSame(
            (int) $assignments[0]['agent_id'],
            (int) $assignments[1]['previous_agent_id'],
            'Second assignment must chain from the first — unbroken previous_agent_id chain',
        );
        self::assertSame($finalOwner, (int) $assignments[1]['agent_id'], 'Ticket owner must equal the LAST committed assignment agent');
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

        $conversation = new Conversation();
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
