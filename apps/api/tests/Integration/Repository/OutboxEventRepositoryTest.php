<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Repository\EventoOutboxRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The outbox SQL that phase 3c-1 moved out of OutboxPublisherService and
 * OutboxDeadLetterCommand. Each test builds its own rows (aggregate_id
 * 990099) and deletes them afterwards.
 */
class OutboxEventRepositoryTest extends KernelTestCase
{
    private const AGGREGATE_ID = 990099;

    private EntityManagerInterface $em;
    private Connection $conn;
    private EventoOutboxRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->repo = static::getContainer()->get(EventoOutboxRepository::class);
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

    private function createEvent(string $createdAt, string $status = 'pending', ?string $publishedAt = null, int $retryCount = 0): string
    {
        $event = new EventoOutbox('ticket', self::AGGREGATE_ID, 'tickets', 'ticket.updated', ['k' => 'v']);
        $this->em->persist($event);
        $this->em->flush();

        $this->conn->executeStatement(
            'UPDATE outbox_events SET created_at = ?, status = ?, published_at = ?, retry_count = ? WHERE id = ?',
            [$createdAt, $status, $publishedAt, $retryCount, $event->getId()]
        );

        return $event->getId();
    }

    /** @return array<string, mixed> */
    private function row(string $id): array|false
    {
        return $this->conn->fetchAssociative('SELECT * FROM outbox_events WHERE id = ?', [$id]);
    }

    public function testFindPendingBatchReturnsUnpublishedPendingRowsOldestFirst(): void
    {
        $second = $this->createEvent('1990-01-01 00:00:02');
        $first = $this->createEvent('1990-01-01 00:00:01');
        $published = $this->createEvent('1990-01-01 00:00:00', 'pending', '1990-01-01 00:00:05');
        $deadLetter = $this->createEvent('1990-01-01 00:00:00', 'dead_letter');

        $rows = $this->repo->findLotePendiente();
        $mine = array_values(array_filter($rows, fn (array $r) => (int) $r['aggregate_id'] === self::AGGREGATE_ID));

        self::assertSame([$first, $second], array_column($mine, 'id'));
        self::assertLessThanOrEqual(100, \count($rows));
        self::assertSame(
            ['id', 'aggregate_type', 'aggregate_id', 'topic', 'event_type', 'payload', 'created_at', 'published_at', 'retry_count', 'status', 'sequence'],
            array_keys($mine[0])
        );
        self::assertNotContains($published, array_column($rows, 'id'));
        self::assertNotContains($deadLetter, array_column($rows, 'id'));
    }

    public function testMarkPublished(): void
    {
        $id = $this->createEvent('1990-01-01 00:00:00');

        $this->repo->marcarPublicado($id, new \DateTimeImmutable('2026-05-06 07:08:09'));

        $row = $this->row($id);
        self::assertSame('published', $row['status']);
        self::assertSame('2026-05-06 07:08:09', $row['published_at']);
    }

    public function testMarkDeadLetter(): void
    {
        $id = $this->createEvent('1990-01-01 00:00:00', 'pending', null, 4);

        $this->repo->marcarDeadLetter($id, 5, 'dead_letter');

        $row = $this->row($id);
        self::assertSame('dead_letter', $row['status']);
        self::assertSame(5, (int) $row['retry_count']);
    }

    public function testIncrementRetryCount(): void
    {
        $id = $this->createEvent('1990-01-01 00:00:00', 'pending', null, 2);

        $this->repo->incrementarReintentos($id);

        $row = $this->row($id);
        self::assertSame(3, (int) $row['retry_count']);
        self::assertSame('pending', $row['status']);
    }

    public function testFindDeadLettersReturnsDeadLetterRowsNewestFirst(): void
    {
        $older = $this->createEvent('2099-01-01 00:00:01', 'dead_letter');
        $newer = $this->createEvent('2099-01-01 00:00:02', 'dead_letter');
        $pending = $this->createEvent('2099-01-01 00:00:03');

        $rows = $this->repo->findDeadLetters();
        $mine = array_values(array_filter($rows, fn (array $r) => (int) $r['aggregate_id'] === self::AGGREGATE_ID));

        self::assertSame([$newer, $older], array_column($mine, 'id'));
        self::assertSame(['id', 'event_type', 'aggregate_id', 'retry_count', 'created_at'], array_keys($mine[0]));
        self::assertNotContains($pending, array_column($rows, 'id'));
        self::assertLessThanOrEqual(100, \count($rows));
    }

    public function testResetDeadLetterToPendingOnlyResetsDeadLetterRows(): void
    {
        $deadLetter = $this->createEvent('1990-01-01 00:00:00', 'dead_letter', null, 5);
        $pending = $this->createEvent('1990-01-01 00:00:00', 'pending', null, 3);

        self::assertSame(1, $this->repo->devolverDeadLetterAPendiente($deadLetter));
        self::assertSame(0, $this->repo->devolverDeadLetterAPendiente($pending));
        self::assertSame(0, $this->repo->devolverDeadLetterAPendiente('missing-id'));

        $row = $this->row($deadLetter);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['retry_count']);
        self::assertSame(3, (int) $this->row($pending)['retry_count']);
    }

    public function testPurgeDeadLettersCreatedBeforeTheCutoff(): void
    {
        $old = $this->createEvent('1990-01-01 00:00:00', 'dead_letter');
        $recent = $this->createEvent('1990-03-01 00:00:00', 'dead_letter');
        $oldPending = $this->createEvent('1990-01-01 00:00:00');

        $deleted = $this->repo->purgarDeadLettersCreadosAntesDe('1990-02-01 00:00:00');

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertFalse($this->row($old));
        self::assertIsArray($this->row($recent));
        self::assertIsArray($this->row($oldPending));
    }

    private function withSequence(string $id, int $sequence): string
    {
        $this->conn->executeStatement('UPDATE outbox_events SET sequence = ? WHERE id = ?', [$sequence, $id]);

        return $id;
    }

    public function testFindResyncRowsReturnsPendingAndPublishedAfterTheSequenceInOrder(): void
    {
        $this->withSequence($this->createEvent('1990-01-01 00:00:00'), 5);
        $third = $this->withSequence($this->createEvent('1990-01-01 00:00:00', 'published'), 9);
        $second = $this->withSequence($this->createEvent('1990-01-01 00:00:00'), 7);
        $this->withSequence($this->createEvent('1990-01-01 00:00:00', 'dead_letter'), 8);

        $rows = $this->repo->findFilasParaResincronizar(self::AGGREGATE_ID, 5);

        self::assertSame([$second, $third], array_column($rows, 'id'));
        self::assertSame(
            ['id', 'aggregate_type', 'aggregate_id', 'topic', 'event_type', 'payload', 'created_at', 'published_at', 'retry_count', 'status', 'sequence'],
            array_keys($rows[0])
        );
    }

    public function testFindResyncRowsReturnsAtMostOneHundredRows(): void
    {
        for ($i = 1; $i <= 101; ++$i) {
            $this->withSequence($this->createEvent('1990-01-01 00:00:00'), $i);
        }

        $rows = $this->repo->findFilasParaResincronizar(self::AGGREGATE_ID, 0);

        self::assertCount(100, $rows);
        self::assertSame(100, (int) $rows[99]['sequence']);
    }
}
