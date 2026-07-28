<?php

namespace App\Repository;

use App\Domain\Workspace\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workspace::class);
    }

    public function save(Workspace $workspace): void
    {
        $this->_em->persist($workspace);
        $this->_em->flush();
    }

    public function findByPhoneNumberId(string $phoneNumberId): ?Workspace
    {
        return $this->findOneBy(['phoneNumberId' => $phoneNumberId]);
    }

    public function findActiveById(int $id): ?Workspace
    {
        return $this->findOneBy(['id' => $id, 'isActive' => true]);
    }
}
