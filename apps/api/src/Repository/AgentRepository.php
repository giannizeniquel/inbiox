<?php

namespace App\Repository;

use App\Domain\Agent\Agent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findAllActive(): array
    {
        return $this->findBy(['active' => true]);
    }
}