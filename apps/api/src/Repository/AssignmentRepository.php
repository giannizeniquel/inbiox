<?php

namespace App\Repository;

use App\Domain\Assignment\Assignment;
use App\Domain\Assignment\AssignmentType;
use App\Domain\Ticket\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assignment::class);
    }

    public function findByTicket(Ticket $ticket): array
    {
        return $this->findBy(['ticket' => $ticket], ['assignedAt' => 'DESC']);
    }

    public function findByAgent(int $agentId): array
    {
        return $this->findBy(['agent' => $agentId], ['assignedAt' => 'DESC']);
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

    /**
     * Count AUTO_ASSIGN assignments for an agent since the start of today.
     *
     * Used as the second tiebreaker in the auto-assignment algorithm.
     * Uses index idx_assignments_agent_assigned_at (agent_id, assigned_at).
     */
    public function countAutoAssignedToday(int $agentId, \DateTimeImmutable $todayStart): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.agent', 'ag')
            ->where('ag.id = :agentId')
            ->andWhere('a.type = :type')
            ->andWhere('a.assignedAt >= :todayStart')
            ->setParameter('agentId', $agentId)
            ->setParameter('type', AssignmentType::AUTO_ASSIGN->value)
            ->setParameter('todayStart', $todayStart)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
