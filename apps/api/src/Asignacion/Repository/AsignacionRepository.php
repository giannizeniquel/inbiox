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

    public function findByAgente(int $agenteId): array
    {
        return $this->findBy(['agente' => $agenteId], ['asignadoAt' => 'DESC']);
    }

    public function findById(int $id): ?Asignacion
    {
        return $this->find($id);
    }

    public function save(Asignacion $asignacion): void
    {
        $this->getEntityManager()->persist($asignacion);
        $this->getEntityManager()->flush();
    }

    /**
     * Busca la asignación actual (la más reciente) de un ticket
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
     * Cuenta las asignaciones AUTO_ASSIGN de un agente desde el inicio del día de hoy.
     *
     * Se usa como segundo criterio de desempate en el algoritmo de asignación automática.
     * Usa el índice idx_assignments_agent_assigned_at (agent_id, assigned_at).
     */
    public function countAsignacionesAutomaticasDeHoy(int $agenteId, \DateTimeImmutable $inicioDeHoy): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.agente', 'ag')
            ->where('ag.id = :agentId')
            ->andWhere('a.tipo = :type')
            ->andWhere('a.asignadoAt >= :todayStart')
            ->setParameter('agentId', $agenteId)
            ->setParameter('type', TipoDeAsignacion::AUTOMATICA->value)
            ->setParameter('todayStart', $inicioDeHoy)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
