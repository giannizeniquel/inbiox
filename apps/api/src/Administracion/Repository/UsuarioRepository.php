<?php

declare(strict_types=1);

namespace App\Administracion\Repository;

use App\Administracion\Usuario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Usuario>
 */
class UsuarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Usuario::class);
    }

    /**
     * Newest first, with the linked agent fetched in the same query.
     *
     * @return Usuario[]
     */
    public function findPaginaConAgente(int $offset, int $limit): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.agente', 'a')
            ->addSelect('a')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('u.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOtroByEmail(string $email, int $excludedId): ?Usuario
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->andWhere('u.id != :id')
            ->setParameter('email', $email)
            ->setParameter('id', $excludedId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
