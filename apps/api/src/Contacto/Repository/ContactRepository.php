<?php

namespace App\Contacto\Repository;

use App\Contacto\Contact;
use App\Ticket\TipoDeEventoDeTicket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    public function findOneByPhone(string $phone): ?Contact
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    public function findById(int $id): ?Contact
    {
        return $this->find($id);
    }

    /**
     * One page of the contact list, most recently updated first (ties by id,
     * newest first). Empty values and unknown status or channel values add no
     * filter; `$search` matches name or phone as a substring.
     *
     * @return list<array<string, mixed>>
     */
    public function findListPage(
        ?string $search,
        ?string $status,
        ?string $channel,
        ?int $ownerId,
        ?string $email,
        int $limit,
        int $offset,
    ): array {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('c.id', 'c.phone', 'c.name', 'c.status', 'c.channel', 'c.owner_id', 'c.email', 'c.created_at', 'c.updated_at')
            ->from('contacts', 'c');

        $this->applyListFilters($qb, $search, $status, $channel, $ownerId, $email);

        return $qb->orderBy('c.updated_at', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->fetchAllAssociative();
    }

    /**
     * Total number of contacts matching the same filters as findListPage().
     */
    public function countList(?string $search, ?string $status, ?string $channel, ?int $ownerId, ?string $email): int
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('contacts', 'c');

        $this->applyListFilters($qb, $search, $status, $channel, $ownerId, $email);

        return (int) $qb->fetchOne();
    }

    /**
     * The contact columns shown in the contact detail, or false when the
     * contact does not exist.
     *
     * @return array<string, mixed>|false
     */
    public function findDetailRow(int $id): array|false
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('c.id', 'c.phone', 'c.name', 'c.status', 'c.channel', 'c.owner_id', 'c.email', 'c.created_at', 'c.updated_at')
            ->from('contacts', 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();
    }

    /**
     * The contact timeline, newest first, at most `$limit` rows: ticket
     * created/updated outbox events, assignments, and the allow-listed
     * `ticket_events` types (WhatsApp 24h window closure/reopen and the six
     * manual resolve/close audit types). Optionally only events before
     * `$before` and only tickets of one workspace (`$phoneNumberId`).
     *
     * Native SQL because DBAL's QueryBuilder cannot express a UNION ALL inside
     * a derived table with an outer ORDER BY/LIMIT, and DQL has no UNION at
     * all. The optional filters are written as `(:param IS NULL OR ...)` so
     * the statement is one fixed string with bound parameters.
     *
     * @return list<array{type: string, occurred_at: string, content: null, ticket_id: mixed, ticket_status: ?string, assigned_to: ?string, agent_id: mixed, direction: null}>
     */
    public function findTimelineRows(int $contactId, ?string $before, int $limit, ?string $phoneNumberId): array
    {
        $sql = <<<'SQL'
        SELECT type, occurred_at, content, ticket_id, ticket_status, assigned_to, agent_id, direction
        FROM (
            SELECT
                CASE
                    WHEN oe.event_type LIKE '%TicketCreated%' THEN 'ticket.created'
                    WHEN oe.event_type LIKE '%TicketUpdated%' THEN 'ticket.status_changed'
                END AS type,
                oe.created_at AS occurred_at,
                NULL AS content,
                t.id AS ticket_id,
                t.status AS ticket_status,
                NULL AS assigned_to,
                NULL AS agent_id,
                NULL AS direction
            FROM outbox_events oe
            JOIN tickets t ON oe.aggregate_id = t.id
            WHERE t.contact_id = :contact_id
              AND oe.aggregate_type = 'Ticket'
              AND (oe.event_type LIKE '%TicketCreated%' OR oe.event_type LIKE '%TicketUpdated%')
              AND (:before_date IS NULL OR oe.created_at < :before_date)
              AND (:phone_number_id IS NULL OR t.phone_number_id = :phone_number_id)

            UNION ALL

            SELECT
                CASE
                    WHEN a.type = 'REASSIGN' THEN 'ticket.reassigned'
                    ELSE 'ticket.assigned'
                END AS type,
                a.assigned_at AS occurred_at,
                NULL AS content,
                t.id AS ticket_id,
                NULL AS ticket_status,
                ag.name AS assigned_to,
                ag.id AS agent_id,
                NULL AS direction
            FROM assignments a
            JOIN tickets t ON a.ticket_id = t.id
            LEFT JOIN agents ag ON a.agent_id = ag.id
            WHERE t.contact_id = :contact_id
              AND (:before_date IS NULL OR a.assigned_at < :before_date)
              AND (:phone_number_id IS NULL OR t.phone_number_id = :phone_number_id)

            UNION ALL

            SELECT
                CASE te.type
                    WHEN :window_expired_closed THEN 'ticket.window_expired_closed'
                    WHEN :window_reopened THEN 'ticket.window_reopened'
                    WHEN :manual_resolved_owner THEN 'ticket.manual_resolved_owner'
                    WHEN :manual_resolved_override THEN 'ticket.manual_resolved_override'
                    WHEN :manual_resolved_unowned THEN 'ticket.manual_resolved_unowned'
                    WHEN :manual_closed_owner THEN 'ticket.manual_closed_owner'
                    WHEN :manual_closed_override THEN 'ticket.manual_closed_override'
                    WHEN :manual_closed_unowned THEN 'ticket.manual_closed_unowned'
                END AS type,
                te.created_at AS occurred_at,
                NULL AS content,
                t.id AS ticket_id,
                t.status AS ticket_status,
                CASE WHEN u.id IS NULL THEN NULL ELSE CONCAT(
                    CASE
                        WHEN JSON_CONTAINS(u.roles, '"ROLE_ADMIN"') THEN 'Admin'
                        WHEN JSON_CONTAINS(u.roles, '"ROLE_SUPERVISOR"') THEN 'Sup'
                        ELSE 'Agente'
                    END,
                    ' ', COALESCE(ag.name, u.email)
                ) END AS assigned_to,
                ag.id AS agent_id,
                NULL AS direction
            FROM ticket_events te
            JOIN tickets t ON te.ticket_id = t.id
            LEFT JOIN `user` u ON te.source = CONCAT('u:', u.id)
            LEFT JOIN agents ag ON ag.user_id = u.id
            WHERE t.contact_id = :contact_id
              AND te.type IN (:allowed_types)
              AND (:before_date IS NULL OR te.created_at < :before_date)
              AND (:phone_number_id IS NULL OR t.phone_number_id = :phone_number_id)
        ) AS timeline
        ORDER BY occurred_at DESC
        LIMIT :limit
        SQL;

        // D6 allow-list: only the WhatsApp 24h window-compliance event types
        // plus the six manual closure/resolution audit types are surfaced.
        $typeParams = [
            'window_expired_closed' => TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED,
            'window_reopened' => TipoDeEventoDeTicket::WINDOW_REOPENED,
            'manual_resolved_owner' => TipoDeEventoDeTicket::MANUAL_RESOLVED_OWNER,
            'manual_resolved_override' => TipoDeEventoDeTicket::MANUAL_RESOLVED_OVERRIDE,
            'manual_resolved_unowned' => TipoDeEventoDeTicket::MANUAL_RESOLVED_UNOWNED,
            'manual_closed_owner' => TipoDeEventoDeTicket::MANUAL_CLOSED_OWNER,
            'manual_closed_override' => TipoDeEventoDeTicket::MANUAL_CLOSED_OVERRIDE,
            'manual_closed_unowned' => TipoDeEventoDeTicket::MANUAL_CLOSED_UNOWNED,
        ];

        $params = $typeParams + [
            'contact_id' => $contactId,
            'allowed_types' => array_values($typeParams),
            'before_date' => $before,
            'phone_number_id' => $phoneNumberId,
            'limit' => $limit,
        ];
        $types = [
            'contact_id' => ParameterType::INTEGER,
            'allowed_types' => ArrayParameterType::STRING,
            'before_date' => ParameterType::STRING,
            'phone_number_id' => ParameterType::STRING,
            'limit' => ParameterType::INTEGER,
        ];

        return $this->getEntityManager()->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAllAssociative();
    }

    private function applyListFilters(
        QueryBuilder $qb,
        ?string $search,
        ?string $status,
        ?string $channel,
        ?int $ownerId,
        ?string $email,
    ): void {
        if ($search) {
            $qb->andWhere('(c.name LIKE :search OR c.phone LIKE :search)')
                ->setParameter('search', "%{$search}%");
        }

        if ($status && in_array($status, ['active', 'inactive'])) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        if ($channel && in_array($channel, ['whatsapp', 'other'])) {
            $qb->andWhere('c.channel = :channel')
                ->setParameter('channel', $channel);
        }

        if ($ownerId) {
            $qb->andWhere('c.owner_id = :ownerId')
                ->setParameter('ownerId', $ownerId);
        }

        if ($email) {
            $qb->andWhere('c.email = :email')
                ->setParameter('email', $email);
        }
    }
}
