<?php

namespace App\Automatizacion\Repository;

use App\Automatizacion\RegistroDeRegla;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegistroDeRegla>
 */
class RegistroDeReglaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistroDeRegla::class);
    }

    public function findByExecutionKey(string $executionKey): ?RegistroDeRegla
    {
        return $this->findOneBy(['executionKey' => $executionKey]);
    }

    public function save(RegistroDeRegla $executionLog): void
    {
        $this->getEntityManager()->persist($executionLog);
        $this->getEntityManager()->flush();
    }
}