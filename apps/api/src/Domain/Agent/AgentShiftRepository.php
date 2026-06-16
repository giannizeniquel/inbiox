<?php

declare(strict_types=1);

namespace App\Domain\Agent;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AgentShiftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentShift::class);
    }

    /** @return AgentShift[] */
    public function findByAgent(int $agentId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.agent = :agentId')
            ->setParameter('agentId', $agentId)
            ->orderBy('s.dayOfWeek', 'ASC')
            ->addOrderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @param int[] $agentIds @return AgentShift[] */
    public function findByAgents(array $agentIds): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.agent IN (:agentIds)')
            ->setParameter('agentIds', $agentIds)
            ->orderBy('s.dayOfWeek', 'ASC')
            ->addOrderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
