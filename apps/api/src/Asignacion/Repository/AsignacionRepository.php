<?php

namespace App\Asignacion\Repository;

use App\Asignacion\Asignacion;
use App\Asignacion\TipoDeAsignacion;
use App\Ticket\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AsignacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asignacion::class);
    }

    public function findByTicket(Ticket $ticket): array
    {
        return $this->findBy(['ticket' => $ticket], ['asignadoAt' => 'DESC']);
    }

    public function findByAgente(int $agentId): array
    {
        return $this->findBy(['agente' => $agentId], ['asignadoAt' => 'DESC']);
    }

    public function findById(int $id): ?Asignacion
    {
        return $this->find($id);
    }

    public function save(Asignacion $assignment): void
    {
        $this->getEntityManager()->persist($assignment);
        $this->getEntityManager()->flush();
    }

    /**
     * Find the current (most recent) assignment for a ticket
     */
    public function findActualByTicket(int $ticketId): ?Asignacion
    {
        return $this->createQueryBuilder('a')
            ->join('a.ticket', 't')
            ->where('t.id = :ticketId')
            ->setParameter('ticketId', $ticketId)
            ->orderBy('a.asignadoAt', 'DESC')
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
    public function countAsignacionesAutomaticasDeHoy(int $agentId, \DateTimeImmutable $todayStart): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.agente', 'ag')
            ->where('ag.id = :agentId')
            ->andWhere('a.tipo = :type')
            ->andWhere('a.asignadoAt >= :todayStart')
            ->setParameter('agentId', $agentId)
            ->setParameter('type', TipoDeAsignacion::AUTOMATICA->value)
            ->setParameter('todayStart', $todayStart)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
