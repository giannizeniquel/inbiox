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
     * Busca los agentes activos de un espacio de trabajo.
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
     * Toma bloqueos de fila sobre las filas indicadas de `agents`: `SELECT id ... FOR UPDATE`.
     * Solo importa el bloqueo; el resultado se descarta.
     *
     * SQL nativo porque DQL no puede hacerlo sin efectos secundarios: una lectura DQL
     * PESSIMISTIC_WRITE hidrata y refresca las entidades Agente gestionadas,
     * que el llamador ya tiene. Se ejecuta sobre la conexión del EntityManager,
     * así que se une a la transacción abierta del llamador.
     *
     * @param int[] $ids no vacío
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
     * Los más recientes primero, con el usuario vinculado obtenido en la misma query.
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
