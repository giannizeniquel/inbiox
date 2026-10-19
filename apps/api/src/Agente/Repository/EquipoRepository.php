<?php

declare(strict_types=1);

namespace App\Agente\Repository;

use App\Administracion\EspacioDeTrabajo;
use App\Agente\Equipo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Equipo>
 */
class EquipoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipo::class);
    }

    /**
     * @param EspacioDeTrabajo[] $espaciosDeTrabajo non-empty
     *
     * @return Equipo[] ordered by name
     */
    public function findByEspaciosDeTrabajo(array $espaciosDeTrabajo): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.espacioDeTrabajo IN (:workspaces)')
            ->setParameter('workspaces', $espaciosDeTrabajo)
            ->orderBy('t.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
