<?php

declare(strict_types=1);

namespace App\Administracion\Repository;

use App\Administracion\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Newest first, with the linked agent fetched in the same query.
     *
     * @return User[]
     */
    public function findPageWithAgent(int $offset, int $limit): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.agent', 'a')
            ->addSelect('a')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('u.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOtherByEmail(string $email, int $excludedId): ?User
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
