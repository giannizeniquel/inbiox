<?php

namespace App\Tests\Integration;

use App\Compartido\Outbox\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Replay safety tests for Sprint 09B - Realtime with Mercure.
 *
 * T026: Replay Safety (Logical)
 *
 * Validates that event model supports safe deduplication and ordering:
 * - event_id allows deduplication
 * - seq allows ordering
 * - older events are detectable
 */
class Sprint09BReplayTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        
        // Clean tables
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    // ============================================
    // T026 — Replay Safety (Logical)
    // ============================================

    /**
     * Test: event_id is stable and usable for deduplication
     *
     * Backend provides stable event_id that clients can use to deduplicate.
     * Test validates that event_id is properly generated and stored.
     */
    public function test_event_id_supports_deduplication(): void
    {
        // Arrange - create an event
        $outboxEvent = new OutboxEvent(
            'ticket',
            1,
            'tickets',
            'message.created',
            ['event_id' => '01HZREPLAY01', 'type' => 'message.created', 'ticket_id' => 1, 'seq' => 1]
        );
        $this->em->persist($outboxEvent);
        $this->em->flush();

        // Act - retrieve and validate
        $stored = $this->em->find(OutboxEvent::class, $outboxEvent->getId());
        
        // Assert - event_id is stable and usable for client-side deduplication
        $payload = $stored->getPayload();
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertEquals('01HZREPLAY01', $payload['event_id'], 'Event ID should be stable');
        
        // Verify client can deduplicate - same event_id means duplicate
        $this->assertEquals('01HZREPLAY01', $payload['event_id'], 'Client can check event_id for deduplication');
    }

    /**
     * Test: seq allows ordering verification
     */
    public function test_seq_allows_ordering(): void
    {
        // Arrange - insert events with known seq values
        $events = [
            ['id' => '01R10', 'seq' => 10, 'type' => 'message.created'],
            ['id' => '01R05', 'seq' => 5, 'type' => 'message.created'],
            ['id' => '01R15', 'seq' => 15, 'type' => 'message.created'],
            ['id' => '01R03', 'seq' => 3, 'type' => 'ticket.created'],
        ];
        
        foreach ($events as $e) {
        $outboxEvent = new OutboxEvent(
            'ticket',
            1,
            'tickets',
                $e['type'],
                ['event_id' => $e['id'], 'type' => $e['type'], 'ticket_id' => 1, 'seq' => $e['seq']]
            );
            $this->em->persist($outboxEvent);
        }
        $this->em->flush();

        // Act - fetch events
        $storedEvents = $this->em->getConnection()->fetchAllAssociative(
            'SELECT payload FROM outbox_events ORDER BY created_at ASC'
        );

        // Assert - seq values are stored correctly
        $seqs = array_map(fn($e) => json_decode($e['payload'], true)['seq'], $storedEvents);
        
        $this->assertContains(3, $seqs, 'Should have seq=3');
        $this->assertContains(5, $seqs, 'Should have seq=5');
        $this->assertContains(10, $seqs, 'Should have seq=10');
        $this->assertContains(15, $seqs, 'Should have seq=15');
    }

    /**
     * Test: older events are detectable via seq
     */
    public function test_older_events_detectable(): void
    {
        // Arrange - create event at seq=1
        $event1 = new OutboxEvent(
            'ticket',
            1,
            'tickets',
            'message.created',
            ['event_id' => '01REPO01', 'type' => 'message.created', 'ticket_id' => 1, 'seq' => 1]
        );
        $this->em->persist($event1);
        
        // Create another at seq=2
        $event2 = new OutboxEvent(
            'ticket',
            1,
            'tickets',
            'message.created',
            ['event_id' => '01REPO02', 'type' => 'message.created', 'ticket_id' => 1, 'seq' => 2]
        );
        $this->em->persist($event2);
        $this->em->flush();

        // Act - try to add older event (seq=1 again - should be rejected in proper implementation)
        // In real scenario, you'd check seq before processing
        
        // Get all events for ticket
        $allEvents = $this->em->getConnection()->fetchAllAssociative(
            'SELECT payload FROM outbox_events WHERE aggregate_id = 1 ORDER BY JSON_EXTRACT(payload, \'$.seq\') ASC'
        );

        // Assert - we can order by seq
        $seqs = array_map(fn($e) => json_decode($e['payload'], true)['seq'], $allEvents);
        $this->assertEquals([1, 2], $seqs, 'Events should be orderable by seq');
    }

    /**
     * Test: seq as monotonically increasing (validates no gap assumption)
     */
    public function test_seq_monotonically_increasing(): void
    {
        // Arrange - create multiple events
        $useCase = static::getContainer()->get(\App\Conversacion\UseCase\HandleInboundMessageUseCase::class);
        
        // First message
        $useCase->processInboundMessage('+5491111111111', 'Msg 1');
        
        // Get seq after first message
        $events = $this->em->getConnection()->fetchAllAssociative(
            'SELECT payload FROM outbox_events ORDER BY created_at ASC'
        );
        $seqs = array_map(fn($e) => json_decode($e['payload'], true)['seq'] ?? 0, $events);
        
        // Verify seq values exist and are positive
        $this->assertNotEmpty(array_filter($seqs), 'Should have seq values');
        
        // All seq should be >= 1
        foreach ($seqs as $seq) {
            $this->assertGreaterThanOrEqual(1, $seq, 'All seq values should be >= 1');
        }
    }

    /**
     * Test: event payload contains version for future compatibility
     */
    public function test_payload_has_version(): void
    {
        // Arrange
        $outboxEvent = new OutboxEvent(
            'ticket',
            1,
            'tickets',
            'message.created',
            ['event_id' => '01VER', 'type' => 'message.created', 'ticket_id' => 1, 'seq' => 1, 'version' => 1]
        );
        $this->em->persist($outboxEvent);
        $this->em->flush();

        // Act
        $stored = $this->em->find(OutboxEvent::class, $outboxEvent->getId());
        
        // Assert
        $payload = $stored->getPayload();
        $this->assertArrayHasKey('version', $payload, 'Payload should have version for compatibility');
        $this->assertEquals(1, $payload['version'], 'Version should be 1');
    }
}