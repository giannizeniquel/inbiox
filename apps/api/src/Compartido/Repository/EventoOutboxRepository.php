<?php

namespace App\Compartido\Repository;

use App\Compartido\Outbox\EventoOutbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads and writes on the raw `outbox_events` table for the publisher and the
 * dead-letter command. They work on plain rows, not managed EventoOutbox
 * entities, so every method uses the DBAL QueryBuilder on the EntityManager's
 * connection and never flushes.
 */
class EventoOutboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventoOutbox::class);
    }

    /**
     * The next batch of pending, unpublished events, oldest first (max 100).
     *
     * @return list<array<string, mixed>>
     */
    public function findLotePendiente(): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id', 'aggregate_type', 'aggregate_id', 'topic', 'event_type', 'payload', 'created_at', 'published_at', 'retry_count', 'status', 'sequence')
            ->from('outbox_events')
            ->where('published_at IS NULL')
            ->andWhere("status = 'pending'")
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(100)
            ->fetchAllAssociative();
    }

    /**
     * Pending or published events of an aggregate with a sequence greater
     * than $afterSequence, lowest sequence first (max 100). Used by the
     * ticket events resync endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function findFilasParaResincronizar(int $aggregateId, int $afterSequence): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id', 'aggregate_type', 'aggregate_id', 'topic', 'event_type', 'payload', 'created_at', 'published_at', 'retry_count', 'status', 'sequence')
            ->from('outbox_events')
            ->where('aggregate_id = :id')
            ->andWhere('sequence > :seq')
            ->andWhere("status IN ('pending', 'published')")
            ->orderBy('sequence', 'ASC')
            ->setParameter('id', $aggregateId)
            ->setParameter('seq', $afterSequence)
            ->setMaxResults(100)
            ->fetchAllAssociative();
    }

    public function marcarPublicado(string $id, \DateTimeImmutable $publishedAt): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('outbox_events')
            ->set('published_at', ':published_at')
            ->set('status', "'published'")
            ->where('id = :id')
            ->setParameter('published_at', $publishedAt->format('Y-m-d H:i:s'))
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Store the final retry count and move the event to `$status` (dead letter).
     */
    public function marcarDeadLetter(string $id, int $retryCount, string $status): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('outbox_events')
            ->set('retry_count', ':retry_count')
            ->set('status', ':status')
            ->where('id = :id')
            ->setParameter('retry_count', $retryCount)
            ->setParameter('status', $status)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    public function incrementarReintentos(string $id): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('outbox_events')
            ->set('retry_count', 'retry_count + 1')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * The latest 100 dead-lettered events, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function findDeadLetters(): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id', 'event_type', 'aggregate_id', 'retry_count', 'created_at')
            ->from('outbox_events')
            ->where("status = 'dead_letter'")
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(100)
            ->fetchAllAssociative();
    }

    /**
     * Put a dead-lettered event back to pending with retry_count = 0.
     *
     * @return int|string affected rows (0 when the event is missing or not dead-lettered)
     */
    public function devolverDeadLetterAPendiente(string $id): int|string
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('outbox_events')
            ->set('status', "'pending'")
            ->set('retry_count', '0')
            ->where('id = :id')
            ->andWhere("status = 'dead_letter'")
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Delete dead-lettered events created before `$cutoff` (`Y-m-d H:i:s`).
     *
     * @return int|string deleted rows
     */
    public function purgarDeadLettersCreadosAntesDe(string $cutoff): int|string
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->delete('outbox_events')
            ->where("status = 'dead_letter'")
            ->andWhere('created_at < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->executeStatement();
    }
}
