<?php

namespace App\Repository;

use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxEventRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OutboxEventRepository extends ServiceEntityRepository implements OutboxEventRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(registry: $registry, class: OutboxEvent::class);
    }

    public function save(OutboxEvent $event): void
    {
        $this->getEntityManager()->persist($event);
        $this->getEntityManager()->flush();
    }

    /**
     * @return OutboxEvent[]
     */
    public function findUnpublished(int $limit = 100): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.publishedAt IS NULL')
            ->andWhere('o.retryCount < :maxRetry')
            ->setParameter('maxRetry', 5)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string[] $ids
     */
    public function markPublished(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $this->createQueryBuilder('o')
            ->update()
            ->set('o.publishedAt', ':publishedAt')
            ->where('o.id IN (:ids)')
            ->setParameter('publishedAt', new \DateTimeImmutable())
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}