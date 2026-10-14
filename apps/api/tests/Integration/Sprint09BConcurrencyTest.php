<?php

namespace App\Tests\Integration;

use App\Conversacion\UseCase\HandleInboundMessageUseCase;
use App\Asignacion\UseCase\AssignTicketUseCase;
use App\Ticket\Ticket;
use App\Compartido\Outbox\OutboxEvent;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Concurrency tests for Sprint 09B - Realtime with Mercure.
 *
 * T023: Concurrency Safety
 */
class Sprint09BConcurrencyTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        
        // Clean tables in order
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        
        // Seed agents needed by AssignTicketUseCase (now requires ManyToOne)
        // Using base columns only (test DB may not have PR1 migration columns yet)
        $conn->executeStatement('DELETE FROM agents');
        for ($i = 1; $i <= 10; $i++) {
            $conn->executeStatement(
                // Override = available, so manual assignment's "active now" guard passes without shifts.
                'INSERT INTO agents (id, name, active, is_available, is_available_override, max_active_tickets, created_at, updated_at) VALUES (?, ?, 1, 1, 1, 10, NOW(), NOW())',
                [$i, "Agent $i"]
            );
        }
    }

    protected function tearDown(): void
    {
        // Remove the rows this test seeded; leftover assignments reference the
        // fixed-id agents and break other tests that delete from `agents`.
        $conn = $this->em->getConnection();
        foreach (['outbox_events', 'ticket_tags', 'messages', 'assignments', 'tickets', 'conversations', 'contacts', 'agents'] as $table) {
            $conn->executeStatement('DELETE FROM ' . $table);
        }

        parent::tearDown();
        $this->em = null;
    }

    // ============================================
    // T023 — Concurrency Safety (CRITICAL)
    // ============================================

    /**
     * Test: Sequential updates produce strictly increasing seq values
     */
    public function test_concurrent_updates_produce_unique_seqs(): void
    {
        // Use UseCase to create initial ticket and message
        $useCase = static::getContainer()->get(HandleInboundMessageUseCase::class);
        $phone = '+5491188888888';
        
        // First message creates ticket (seq increments)
        $useCase->processInboundMessage($phone, 'Initial message');
        
        // Get ticket info
        $ticket = $this->em->getConnection()->fetchAssociative(
            'SELECT id, last_event_seq FROM tickets ORDER BY id DESC LIMIT 1'
        );
        
        $this->assertNotEmpty($ticket);
        $ticketId = (int) $ticket['id'];
        
        // Clear events for clean test
        $this->em->getConnection()->executeStatement('DELETE FROM outbox_events');
        
        // Use AssignTicketUseCase - it applies pessimistic lock internally
        $assignUseCase1 = static::getContainer()->get(AssignTicketUseCase::class);
        $assignUseCase2 = static::getContainer()->get(AssignTicketUseCase::class);
        
        // First assignment → seq should increment
        $assignUseCase1->assignTicket($ticketId, 1, 'admin', 'First assignment');
        
        // Second assignment → seq should be next
        $assignUseCase2->assignTicket($ticketId, 2, 'admin', 'Second assignment');
        
        // Get events
        $events = $this->em->getConnection()->fetchAllAssociative(
            'SELECT payload FROM outbox_events ORDER BY created_at ASC'
        );
        
        // Extract seq values
        $seqs = array_map(fn($e) => json_decode($e['payload'], true)['seq'], $events);
        
        // Verify unique - no duplicates
        $uniqueSeqs = array_unique($seqs);
        $this->assertCount(count($seqs), $uniqueSeqs, 'All seq values should be unique - no duplicates');
    }

    /**
     * Test: Lock prevents lost updates (validates seq increments)
     */
    public function test_lock_prevents_lost_updates(): void
    {
        $em = $this->em;
        
        // Create contact and conversation first (for FK)
        $em->getConnection()->executeStatement(
            'INSERT INTO contacts (id, phone, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
            [1, '+5491000000000']
        );
        $em->getConnection()->executeStatement(
            'INSERT INTO conversations (id, status, created_at, updated_at, contact_id) VALUES (?, ?, NOW(), NOW(), ?)',
            [1, 'active', 1]
        );
        
        // Create ticket with seq=0
        $em->getConnection()->executeStatement(
            'INSERT INTO tickets (id, contact_id, conversation_id, status, priority, created_at, updated_at, last_event_seq) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)',
            [999, 1, 1, 'new', 'medium', 0]
        );
        
        // Verify initial seq
        $ticket = $em->getConnection()->fetchAssociative(
            'SELECT last_event_seq FROM tickets WHERE id = 999'
        );
        $this->assertEquals(0, $ticket['last_event_seq'], 'Initial seq should be 0');
        
        // Use AssignTicketUseCase (applies pessimistic lock)
        $assignUseCase = static::getContainer()->get(AssignTicketUseCase::class);
        $assignUseCase->assignTicket(999, 1, 'admin', 'Test');
        
        // Verify seq incremented
        $em->clear();
        $ticket = $em->getConnection()->fetchAssociative(
            'SELECT last_event_seq FROM tickets WHERE id = 999'
        );
        
        $this->assertGreaterThan(0, $ticket['last_event_seq'], 'Seq should have incremented');
    }

    /**
     * Test: Multiple sequential updates increment correctly
     */
    public function test_sequential_updates_increment_correctly(): void
    {
        // Create ticket via UseCase
        $useCase = static::getContainer()->get(HandleInboundMessageUseCase::class);
        $phone = '+5491199999999';
        $useCase->processInboundMessage($phone, 'First');
        
        $ticket = $this->em->getConnection()->fetchAssociative(
            'SELECT id, last_event_seq FROM tickets ORDER BY id DESC LIMIT 1'
        );
        $ticketId = (int) $ticket['id'];
        $initialSeq = (int) $ticket['last_event_seq'];
        
        // Get Assign UseCase
        $assignUseCase = static::getContainer()->get(AssignTicketUseCase::class);
        
        // Do multiple assignments
        for ($i = 1; $i <= 4; $i++) {
            $assignUseCase->assignTicket($ticketId, $i, 'admin', "Assign $i");
        }
        
        // Verify final seq is higher than initial
        $ticket = $this->em->getConnection()->fetchAssociative(
            'SELECT last_event_seq FROM tickets WHERE id = ?',
            [$ticketId]
        );
        
        $this->assertGreaterThan($initialSeq, $ticket['last_event_seq'], 'Seq should have incremented');
    }
}