<?php

namespace App\Agente\Repository;

use App\Agente\Agent;
use App\Administracion\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * @param int[] $ids
     * @return Agent[]
     */
    public function findByIds(array $ids): array
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('a')
            ->from(Agent::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
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

    /**
     * Take row locks on the given `agents` rows: `SELECT id ... FOR UPDATE`.
     * Only the lock matters; the result is discarded.
     *
     * Native SQL because DQL cannot do this without side effects: a DQL
     * PESSIMISTIC_WRITE read hydrates and refreshes the managed Agent
     * entities, which the caller already holds. Runs on the EntityManager's
     * connection, so it joins the caller's open transaction.
     *
     * @param int[] $ids non-empty
     */
    public function lockRowsForUpdate(array $ids): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id FROM agents WHERE id IN (?) FOR UPDATE',
            [array_values($ids)],
            [ArrayParameterType::INTEGER],
        );
    }
}
