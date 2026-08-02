<?php

namespace App\Repository;

use App\Domain\Agent\Agent;
use App\Domain\Workspace\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    public function save(Agent $agent): void
    {
        $this->_em->persist($agent);
        $this->_em->flush();
    }

    public function findById(int $id): ?Agent
    {
        return $this->find($id);
    }

    public function findAllActive(): array
    {
        return $this->findBy(['active' => true]);
    }

    /** @return Agent[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.workspaces', 'w')
            ->where('w.id = :workspaceId')
            ->setParameter('workspaceId', $workspace->getId())
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active agents in a workspace.
     *
     * @return Agent[]
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.workspaces', 'w')
            ->where('w.id = :workspaceId')
            ->andWhere('a.active = true')
            ->setParameter('workspaceId', $workspace->getId())
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}