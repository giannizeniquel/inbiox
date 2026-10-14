<?php

namespace App\Repository;

use App\Domain\Rule\RuleExecutionLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RuleExecutionLog>
 */
class RuleExecutionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RuleExecutionLog::class);
    }

    public function findByExecutionKey(string $executionKey): ?RuleExecutionLog
    {
        return $this->findOneBy(['executionKey' => $executionKey]);
    }

    public function save(RuleExecutionLog $executionLog): void
    {
        $this->getEntityManager()->persist($executionLog);
        $this->getEntityManager()->flush();
    }
}