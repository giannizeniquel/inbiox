<?php

declare(strict_types=1);

namespace App\Autenticacion\Repository;

use App\Autenticacion\RefreshToken;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Gesdinet\JWTRefreshTokenBundle\Doctrine\RefreshTokenRepositoryInterface;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 * @implements RefreshTokenRepositoryInterface<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    /**
     * @return RefreshToken[]
     */
    public function findInvalid($datetime = null): array
    {
        $qb = $this->createQueryBuilder('r');

        $qb->where($qb->expr()->lt('r.valid', ':datetime'))
            ->setParameter('datetime', $datetime ?? new \DateTime());

        return $qb->getQuery()->getResult();
    }
}
