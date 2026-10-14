<?php

namespace App\Ticket\Repository;

use App\Ticket\TicketEventType;
use App\Ticket\Ticket;
use App\Ticket\TicketStatus;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Locking read of the raw `tickets` row: `SELECT * ... FOR UPDATE`.
     * Returns the row as an associative array, or false when it does not exist.
     *
     * Native SQL because DQL cannot do this without side effects: a DQL
     * PESSIMISTIC_WRITE read hydrates (and with HINT_REFRESH overwrites) the
     * managed Ticket entity, while the callers need the latest-committed
     * column values as a plain array and leave the identity map untouched.
     * Runs on the EntityManager's connection, so it joins the caller's
     * open transaction.
     *
     * @return array<string, mixed>|false
     */
    public function findRowByIdForUpdate(int $id): array|false
    {
        return $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT * FROM tickets WHERE id = ? FOR UPDATE',
            [$id]
        )->fetchAssociative();
    }

    /**
     * Status change written by TransitionTicketStatusUseCase::execute():
     * sets status and updated_at, plus each SLA timestamp the caller asks for,
     * in this SET order: first_response_at, resolved_at, closed_at.
     */
    public function updateStatusAfterTransition(
        int $id,
        string $status,
        bool $setFirstResponse,
        bool $setResolved,
        bool $setClosed,
    ): void {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('updated_at', 'NOW()');

        if ($setFirstResponse) {
            $qb->set('first_response_at', 'NOW()');
        }
        if ($setResolved) {
            $qb->set('resolved_at', 'NOW()');
        }
        if ($setClosed) {
            $qb->set('closed_at', 'NOW()');
        }

        $qb->where('id = :id')
            ->setParameter('status', $status)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Close write: status, closed_at = NOW(), updated_at = NOW().
     */
    public function markClosed(int $id, string $status): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('closed_at', 'NOW()')
            ->set('updated_at', 'NOW()')
            ->where('id = :id')
            ->setParameter('status', $status)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Reopen write: status, closed_at = NULL, updated_at = NOW().
     */
    public function markReopened(int $id, string $status): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('closed_at', 'NULL')
            ->set('updated_at', 'NOW()')
            ->where('id = :id')
            ->setParameter('status', $status)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * First agent reply without a status change:
     * first_response_at = NOW(), updated_at = NOW().
     */
    public function markFirstResponse(int $id): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('first_response_at', 'NOW()')
            ->set('updated_at', 'NOW()')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Type of the ticket's most recent `ticket_events` row
     * (by created_at, then id), or false when it has no events.
     */
    public function findLatestEventType(int $ticketId): string|false
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('type')
            ->from('ticket_events')
            ->where('ticket_id = :ticketId')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('ticketId', $ticketId)
            ->fetchOne();
    }

    /**
     * The ticket's `phone_number_id` column, or null when the ticket does not
     * exist or the column is null. Read straight from the table, so it does
     * not touch the managed entity.
     */
    public function findPhoneNumberId(int $id): ?string
    {
        $phoneNumberId = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('phone_number_id')
            ->from('tickets')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchOne();

        return $phoneNumberId === false || $phoneNumberId === null ? null : (string) $phoneNumberId;
    }

    /**
     * Ids of tickets in one of `$statuses` whose last inbound message is
     * older than `$threshold` (`Y-m-d H:i:s`), oldest first, at most `$limit`.
     * Tickets with a null `last_inbound_message_at` never match.
     *
     * @param string[] $statuses
     * @return list<mixed>
     */
    public function findIdsWithExpiredWindow(array $statuses, string $threshold, int $limit): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id')
            ->from('tickets')
            ->where('status IN (:statuses)')
            ->andWhere('last_inbound_message_at IS NOT NULL')
            ->andWhere('last_inbound_message_at < :threshold')
            ->orderBy('last_inbound_message_at', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('statuses', $statuses, ArrayParameterType::STRING)
            ->setParameter('threshold', $threshold)
            ->fetchFirstColumn();
    }
}
