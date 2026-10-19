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
    public function findByAgente(int $agentId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.agente = :agentId')
            ->setParameter('agentId', $agentId)
            ->orderBy('s.diaDeLaSemana', 'ASC')
            ->addOrderBy('s.horaDeInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @param int[] $agentIds @return Turno[] */
    public function findByAgentes(array $agentIds): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.agente IN (:agentIds)')
            ->setParameter('agentIds', $agentIds)
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
    public function deleteTodosDeAgente(int $agentId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.agente = :agentId')
            ->setParameter('agentId', $agentId)
            ->getQuery()
            ->execute();
    }
}
