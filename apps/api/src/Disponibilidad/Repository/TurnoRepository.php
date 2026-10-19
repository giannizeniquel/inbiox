<?php

declare(strict_types=1);

namespace App\Disponibilidad\Repository;

use App\Disponibilidad\Turno;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TurnoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Turno::class);
    }

    /** @return Turno[] */
    public function findByAgente(int $agenteId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.agente = :agentId')
            ->setParameter('agentId', $agenteId)
            ->orderBy('s.diaDeLaSemana', 'ASC')
            ->addOrderBy('s.horaDeInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @param int[] $agenteIds @return Turno[] */
    public function findByAgentes(array $agenteIds): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.agente IN (:agentIds)')
            ->setParameter('agentIds', $agenteIds)
            ->orderBy('s.diaDeLaSemana', 'ASC')
            ->addOrderBy('s.horaDeInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete all shifts for a given agent.
     *
     * @return int Number of deleted rows
     */
    public function deleteTodosDeAgente(int $agenteId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.agente = :agentId')
            ->setParameter('agentId', $agenteId)
            ->getQuery()
            ->execute();
    }
}
