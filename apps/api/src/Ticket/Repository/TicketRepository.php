<?php

namespace App\Ticket\Repository;

use App\Ticket\TicketEventType;
use App\Ticket\Ticket;
use App\Ticket\TicketStatus;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.contact = :contact')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('contact', $contact->getId() ?? 0)
            ->setParameter('statuses', [
                TicketStatus::NEW->value,
                TicketStatus::ACTIVE->value,
                TicketStatus::WAITING->value,
            ]);

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phoneNumberId = :phoneNumberId')
               ->setParameter('phoneNumberId', $phoneNumberId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findNotClosedByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.contact = :contact')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('contact', $contact->getId() ?? 0)
            ->setParameter('statuses', [
                TicketStatus::NEW->value,
                TicketStatus::ACTIVE->value,
                TicketStatus::WAITING->value,
                TicketStatus::RESOLVED->value,
            ]);

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phoneNumberId = :phoneNumberId')
               ->setParameter('phoneNumberId', $phoneNumberId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findById(int $id): ?Ticket
    {
        return $this->find($id);
    }

    /**
     * Locking read: PESSIMISTIC_WRITE acquired as part of the SELECT itself
     * (design.md D1). MUST be the transaction's FIRST statement so nothing
     * else can open a consistent-read snapshot ahead of the lock.
     *
     * Query::HINT_REFRESH forces Doctrine to overwrite any stale identity-map
     * copy with the just-locked row instead of silently reusing it.
     *
     * Rejected: `findById()` + `$em->lock()` (AssignTicketUseCase convention)
     * — under MySQL REPEATABLE READ, `findById()` opens the consistent-read
     * snapshot BEFORE the lock is acquired, so a later `refresh()` still
     * reads the pre-lock snapshot. TOCTOU survives.
     */
    public function findByIdForUpdate(int $id): ?Ticket
    {
        return $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Find a `closed` ticket for this contact eligible for system-triggered
     * reopen: the ticket's MOST RECENT `ticket_events` row must be of type
     * `window_expired_closed`. A ticket with zero events, a different
     * latest event type, or closed for another reason yields no match —
     * unchanged, so `HandleInboundMessageUseCase` falls back to creating a
     * new ticket, exactly as it does today (design.md).
     *
     * DQL cannot express a correlated `ORDER BY ... LIMIT 1` subquery, so
     * this uses the connection directly (precedent: `TransitionTicketStatusUseCase`),
     * then rehydrates via `$this->find($id)` so the caller receives a
     * managed entity it can `lock()`.
     */
    public function findWindowExpiredClosedByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket
    {
        $sql = 'SELECT t.id
                FROM tickets t
                WHERE t.contact_id = :contact_id
                  AND t.status = :status
                  AND (SELECT te.type
                       FROM ticket_events te
                       WHERE te.ticket_id = t.id
                       ORDER BY te.created_at DESC, te.id DESC
                       LIMIT 1) = :window_expired_closed';
        $params = [
            'contact_id' => $contact->getId() ?? 0,
            'status' => TicketStatus::CLOSED->value,
            'window_expired_closed' => TicketEventType::WINDOW_EXPIRED_CLOSED,
        ];

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phone_number_id';
            $params['phone_number_id'] = $phoneNumberId;
        }

        $sql .= ' ORDER BY t.updated_at DESC, t.id DESC LIMIT 1';

        $id = $this->getEntityManager()->getConnection()->fetchOne($sql, $params);

        return $id !== false ? $this->find((int) $id) : null;
    }

    public function findByConversation(Conversation $conversation): ?Ticket
    {
        return $this->findOneBy(['conversation' => $conversation]);
    }

    /**
     * Count active tickets currently assigned to an agent.
     *
     * Active tickets are those with status IN ('new', 'active', 'waiting').
     * Uses index idx_tickets_owner_status (assigned_agent_id, status).
     */
    public function countActiveForAgent(int $agentId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.assignedAgentId = :agentId')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('agentId', $agentId)
            ->setParameter('statuses', [
                TicketStatus::NEW->value,
                TicketStatus::ACTIVE->value,
                TicketStatus::WAITING->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
