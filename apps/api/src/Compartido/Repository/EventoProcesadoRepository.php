<?php

namespace App\Compartido\Repository;

use App\Compartido\EventoProcesado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventoProcesado>
 */
class EventoProcesadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventoProcesado::class);
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
    public function findByEventId(string $eventId): ?EventoProcesado
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }

    /**
     * Save a processed event
     */
    public function save(EventoProcesado $event): void
    {
        $this->getEntityManager()->persist($event);
        $this->getEntityManager()->flush();
    }
}