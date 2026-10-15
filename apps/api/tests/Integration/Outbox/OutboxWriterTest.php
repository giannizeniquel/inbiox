<?php

declare(strict_types=1);

namespace App\Tests\Integration\Outbox;

use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The single outbox writer (phase 3e). Rows use aggregate_id 990199 and are
 * deleted afterwards.
 */
final class OutboxWriterTest extends KernelTestCase
{
    private const AGGREGATE_ID = 990199;

    private Connection $conn;
    private OutboxWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->writer = static::getContainer()->get(OutboxWriter::class);
        $this->deleteMyRows();
    }

    protected function tearDown(): void
    {
        $this->deleteMyRows();
        parent::tearDown();
    }

    private function deleteMyRows(): void
    {
        $this->conn->executeStatement('DELETE FROM outbox_events WHERE aggregate_id = ?', [self::AGGREGATE_ID]);
    }

    public function testWritesEveryEventAsAPendingRow(): void
    {
        $this->writer->write(
            new OutboxEvent('ticket', self::AGGREGATE_ID, 'tickets', 'ticket.read', ['ticketId' => self::AGGREGATE_ID]),
            new OutboxEvent('agent', self::AGGREGATE_ID, 'agent/990199/shifts', 'shift.changed', ['seq' => 7], 7),
        );

        $rows = $this->conn->fetchAllAssociative(
            'SELECT aggregate_type, topic, event_type, payload, status, sequence, retry_count, published_at
             FROM outbox_events WHERE aggregate_id = ? ORDER BY aggregate_type DESC',
            [self::AGGREGATE_ID],
        );

        self::assertCount(2, $rows);
        self::assertSame(['ticket', 'tickets', 'ticket.read', 'pending', 0, 0, null], [
            $rows[0]['aggregate_type'], $rows[0]['topic'], $rows[0]['event_type'], $rows[0]['status'],
            (int) $rows[0]['sequence'], (int) $rows[0]['retry_count'], $rows[0]['published_at'],
        ]);
        self::assertSame(['ticketId' => self::AGGREGATE_ID], \json_decode($rows[0]['payload'], true));
        self::assertSame(['agent', 'agent/990199/shifts', 'shift.changed', 7], [
            $rows[1]['aggregate_type'], $rows[1]['topic'], $rows[1]['event_type'], (int) $rows[1]['sequence'],
        ]);
    }
}
