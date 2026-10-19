<?php

namespace App\Agente\Repository;

use App\Agente\Agente;
use App\Administracion\EspacioDeTrabajo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

class AgenteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agente::class);
    }

    public function save(Agente $agente): void
    {
        $this->_em->persist($agente);
        $this->_em->flush();
    }

    public function findById(int $id): ?Agente
    {
        return $this->find($id);
    }

    /**
     * @param int[] $ids
     * @return Agente[]
     */
    public function findByIds(array $ids): array
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('a')
            ->from(Agente::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function findActivos(): array
    {
        return $this->findBy(['activo' => true]);
    }

    /** @return Agente[] */
    public function findByEspacioDeTrabajo(EspacioDeTrabajo $espacioDeTrabajo): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.espaciosDeTrabajo', 'w')
            ->where('w.id = :workspaceId')
            ->setParameter('workspaceId', $espacioDeTrabajo->getId())
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active agents in a workspace.
     *
     * @return Agente[]
     */
    public function findActivosByEspacioDeTrabajo(EspacioDeTrabajo $espacioDeTrabajo): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.espaciosDeTrabajo', 'w')
            ->where('w.id = :workspaceId')
            ->andWhere('a.activo = true')
            ->setParameter('workspaceId', $espacioDeTrabajo->getId())
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

    /**
     * Newest first, with the linked user fetched in the same query.
     *
     * @return Agente[]
     */
    public function findPaginaConUsuario(int $offset, int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.usuario', 'u')
            ->addSelect('u')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
