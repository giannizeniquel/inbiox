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

    public function findByClaveDeEjecucion(string $claveDeEjecucion): ?RegistroDeRegla
    {
        return $this->findOneBy(['claveDeEjecucion' => $claveDeEjecucion]);
    }

    public function save(RegistroDeRegla $registroDeRegla): void
    {
        $this->getEntityManager()->persist($registroDeRegla);
        $this->getEntityManager()->flush();
    }
}