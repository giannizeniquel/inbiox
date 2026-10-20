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
     * Verifica si un evento ya fue procesado
     */
    public function exists(string $eventoId): bool
    {
        return $this->findOneBy(['eventoId' => $eventoId]) !== null;
    }

    /**
     * Busca un evento procesado por su ID de evento
     */
    public function findByEventoId(string $eventoId): ?EventoProcesado
    {
        return $this->findOneBy(['eventoId' => $eventoId]);
    }

    /**
     * Guarda un evento procesado
     */
    public function save(EventoProcesado $evento): void
    {
        $this->getEntityManager()->persist($evento);
        $this->getEntityManager()->flush();
    }
}