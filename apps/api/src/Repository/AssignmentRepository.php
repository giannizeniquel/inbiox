<?php

namespace App\Repository;

use App\Domain\Assignment\Assignment;
use App\Domain\Assignment\AssignmentRepositoryInterface;
use App\Domain\Ticket\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AssignmentRepository extends ServiceEntityRepository implements AssignmentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assignment::class);
    }

    public function findByTicket(Ticket $ticket): array
    {
        return $this->findBy(['ticket' => $ticket], ['assignedAt' => 'DESC']);
    }

    public function findByAgent(string $agentId): array
    {
        return $this->findBy(['agentId' => $agentId], ['assignedAt' => 'DESC']);
    }

    public function findById(int $id): ?Assignment
    {
        return $this->find($id);
    }

    public function save(Assignment $assignment): void
    {
        $this->getEntityManager()->persist($assignment);
        $this->getEntityManager()->flush();
    }

    /**
     * Find the current (most recent) assignment for a ticket
     */
    public function findCurrentByTicket(int $ticketId): ?Assignment
    {
        return $this->createQueryBuilder('a')
            ->join('a.ticket', 't')
            ->where('t.id = :ticketId')
            ->setParameter('ticketId', $ticketId)
            ->orderBy('a.assignedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
