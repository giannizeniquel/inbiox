<?php

namespace App\Compartido\Repository;

use App\Compartido\Outbox\EventoOutbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lecturas y escrituras sobre la tabla cruda `outbox_events` para el publisher y el
 * comando de dead letter. Trabajan con filas planas, no con entidades EventoOutbox
 * gestionadas, así que todos los métodos usan el QueryBuilder de DBAL sobre la conexión
 * del EntityManager y nunca hacen flush.
 */
class EventoOutboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventoOutbox::class);
    }

    /**
     * El siguiente lote de eventos pendientes y no publicados, los más antiguos primero (máx. 100).
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

    public function marcarPublicado(string $id, \DateTimeImmutable $publicadoAt): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('outbox_events')
            ->set('published_at', ':published_at')
            ->set('status', "'published'")
            ->where('id = :id')
            ->setParameter('published_at', $publicadoAt->format('Y-m-d H:i:s'))
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Guarda el contador final de reintentos y pasa el evento a `$estado` (dead letter).
     */
    public function marcarDeadLetter(string $id, int $reintentos, string $estado): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('outbox_events')
            ->set('retry_count', ':retry_count')
            ->set('status', ':status')
            ->where('id = :id')
            ->setParameter('retry_count', $reintentos)
            ->setParameter('status', $estado)
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
     * Los últimos 100 eventos en dead letter, los más recientes primero.
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
     * Devuelve un evento en dead letter a pending con retry_count = 0.
     *
     * @return int|string filas afectadas (0 cuando el evento no existe o no está en dead letter)
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
     * Borra los eventos en dead letter creados antes de `$corte` (`Y-m-d H:i:s`).
     *
     * @return int|string filas borradas
     */
    public function purgarDeadLettersCreadosAntesDe(string $corte): int|string
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->delete('outbox_events')
            ->where("status = 'dead_letter'")
            ->andWhere('created_at < :cutoff')
            ->setParameter('cutoff', $corte)
            ->executeStatement();
    }
}
