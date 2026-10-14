<?php

namespace App\Compartido\Repository;

use App\Compartido\ProcessedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessedEvent>
 */
class ProcessedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedEvent::class);
    }

    /**
     * Check if an event has already been processed
     */
    public function exists(string $eventId): bool
    {
        return $this->findOneBy(['eventId' => $eventId]) !== null;
    }

    /**
     * Find processed event by event ID
     */
    public function findByEventId(string $eventId): ?ProcessedEvent
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }

    /**
     * Save a processed event
     */
    public function save(ProcessedEvent $event): void
    {
        $this->getEntityManager()->persist($event);
        $this->getEntityManager()->flush();
    }
}