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
    public function exists(string $eventoId): bool
    {
        return $this->findOneBy(['eventoId' => $eventoId]) !== null;
    }

    /**
     * Find processed event by event ID
     */
    public function findByEventoId(string $eventoId): ?EventoProcesado
    {
        return $this->findOneBy(['eventoId' => $eventoId]);
    }

    /**
     * Save a processed event
     */
    public function save(EventoProcesado $evento): void
    {
        $this->getEntityManager()->persist($evento);
        $this->getEntityManager()->flush();
    }
}