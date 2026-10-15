<?php

declare(strict_types=1);

namespace App\Agente\Repository;

use App\Administracion\Workspace;
use App\Agente\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Team>
 */
class TeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Team::class);
    }

    /**
     * @param Workspace[] $workspaces non-empty
     *
     * @return Team[] ordered by name
     */
    public function findByWorkspaces(array $workspaces): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.workspace IN (:workspaces)')
            ->setParameter('workspaces', $workspaces)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
