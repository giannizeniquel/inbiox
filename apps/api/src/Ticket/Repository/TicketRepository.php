<?php

namespace App\Ticket\Repository;

use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findAbiertoByContacto(Contacto $contacto, ?string $phoneNumberId = null): ?Ticket
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.contacto = :contact')
            ->andWhere('t.estado IN (:statuses)')
            ->setParameter('contact', $contacto->getId() ?? 0)
            ->setParameter('statuses', [
                EstadoDeTicket::NUEVO->value,
                EstadoDeTicket::ACTIVO->value,
                EstadoDeTicket::EN_ESPERA->value,
            ]);

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phoneNumberId = :phoneNumberId')
               ->setParameter('phoneNumberId', $phoneNumberId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findNoCerradoByContacto(Contacto $contacto, ?string $phoneNumberId = null): ?Ticket
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.contacto = :contact')
            ->andWhere('t.estado IN (:statuses)')
            ->setParameter('contact', $contacto->getId() ?? 0)
            ->setParameter('statuses', [
                EstadoDeTicket::NUEVO->value,
                EstadoDeTicket::ACTIVO->value,
                EstadoDeTicket::EN_ESPERA->value,
                EstadoDeTicket::RESUELTO->value,
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
     * Rejected: `findById()` + `$em->lock()` (AsignarTicketUseCase convention)
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
     * unchanged, so `ProcesarMensajeEntranteUseCase` falls back to creating a
     * new ticket, exactly as it does today (design.md).
     *
     * DQL cannot express a correlated `ORDER BY ... LIMIT 1` subquery, so
     * this uses the connection directly (precedent: `CambiarEstadoDeTicketUseCase`),
     * then rehydrates via `$this->find($id)` so the caller receives a
     * managed entity it can `lock()`.
     */
    public function findCerradoPorVentanaVencidaByContacto(Contacto $contacto, ?string $phoneNumberId = null): ?Ticket
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
            'contact_id' => $contacto->getId() ?? 0,
            'status' => EstadoDeTicket::CERRADO->value,
            'window_expired_closed' => TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO,
        ];

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phone_number_id';
            $params['phone_number_id'] = $phoneNumberId;
        }

        $sql .= ' ORDER BY t.updated_at DESC, t.id DESC LIMIT 1';

        $id = $this->getEntityManager()->getConnection()->fetchOne($sql, $params);

        return $id !== false ? $this->find((int) $id) : null;
    }

    public function findByConversacion(Conversacion $conversacion): ?Ticket
    {
        return $this->findOneBy(['conversacion' => $conversacion]);
    }

    /**
     * Count active tickets currently assigned to an agent.
     *
     * Active tickets are those with status IN ('new', 'active', 'waiting').
     * Uses index idx_tickets_owner_status (assigned_agent_id, status).
     */
    public function countActivosDeAgente(int $agenteId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.agenteAsignadoId = :agentId')
            ->andWhere('t.estado IN (:statuses)')
            ->setParameter('agentId', $agenteId)
            ->setParameter('statuses', [
                EstadoDeTicket::NUEVO->value,
                EstadoDeTicket::ACTIVO->value,
                EstadoDeTicket::EN_ESPERA->value,
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
    public function findFilaByIdForUpdate(int $id): array|false
    {
        return $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT * FROM tickets WHERE id = ? FOR UPDATE',
            [$id]
        )->fetchAssociative();
    }

    /**
     * Status change written by CambiarEstadoDeTicketUseCase::execute():
     * sets status and updated_at, plus each SLA timestamp the caller asks for,
     * in this SET order: first_response_at, resolved_at, closed_at.
     */
    public function updateEstadoTrasTransicion(
        int $id,
        string $estado,
        bool $setPrimeraRespuesta,
        bool $setResuelto,
        bool $setCerrado,
    ): void {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('updated_at', 'NOW()');

        if ($setPrimeraRespuesta) {
            $qb->set('first_response_at', 'NOW()');
        }
        if ($setResuelto) {
            $qb->set('resolved_at', 'NOW()');
        }
        if ($setCerrado) {
            $qb->set('closed_at', 'NOW()');
        }

        $qb->where('id = :id')
            ->setParameter('status', $estado)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Close write: status, closed_at = NOW(), updated_at = NOW().
     */
    public function marcarCerrado(int $id, string $estado): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('closed_at', 'NOW()')
            ->set('updated_at', 'NOW()')
            ->where('id = :id')
            ->setParameter('status', $estado)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Reopen write: status, closed_at = NULL, updated_at = NOW().
     */
    public function marcarReabierto(int $id, string $estado): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('closed_at', 'NULL')
            ->set('updated_at', 'NOW()')
            ->where('id = :id')
            ->setParameter('status', $estado)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * First agent reply without a status change:
     * first_response_at = NOW(), updated_at = NOW().
     */
    public function marcarPrimeraRespuesta(int $id): void
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
    public function findUltimoTipoDeEvento(int $ticketId): string|false
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
     * Ids of tickets in one of `$estados` whose last inbound message is
     * older than `$threshold` (`Y-m-d H:i:s`), oldest first, at most `$limit`.
     * Tickets with a null `last_inbound_message_at` never match.
     *
     * @param string[] $estados
     * @return list<mixed>
     */
    public function findIdsConVentanaVencida(array $estados, string $threshold, int $limit): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id')
            ->from('tickets')
            ->where('status IN (:statuses)')
            ->andWhere('last_inbound_message_at IS NOT NULL')
            ->andWhere('last_inbound_message_at < :threshold')
            ->orderBy('last_inbound_message_at', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('statuses', $estados, ArrayParameterType::STRING)
            ->setParameter('threshold', $threshold)
            ->fetchFirstColumn();
    }

    /**
     * One page of the inbox ticket list (`ListTicketsQuery`): the ticket, its
     * contact, its assigned agent, and that agent's count of active tickets,
     * newest `updated_at` first (ties by id, newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginaDeLista(FiltrosDeTicket $filtros, int $limit, int $offset): array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select(
                't.id', 't.status', 't.priority', 't.assigned_agent_id', 't.created_at', 't.updated_at',
                't.last_inbound_message_at',
                'c.phone', 'c.name AS contact_name', 'conv.id AS conv_id',
                'a.name AS agent_name', 'a.max_active_tickets',
                "(SELECT COUNT(*) FROM tickets t2 WHERE t2.assigned_agent_id = a.id AND t2.status IN ('new', 'active', 'waiting')) AS current_active_tickets",
            )
            ->from('tickets', 't')
            ->innerJoin('t', 'conversations', 'conv', 't.conversation_id = conv.id')
            ->innerJoin('t', 'contacts', 'c', 'conv.contact_id = c.id')
            ->leftJoin('t', 'agents', 'a', 't.assigned_agent_id = a.id');

        $this->aplicarFiltrosDeLista($qb, $filtros);

        return $qb->orderBy('t.updated_at', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->fetchAllAssociative();
    }

    /**
     * Total number of tickets matching the list filters (no joins needed).
     */
    public function countLista(FiltrosDeTicket $filtros): int
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('tickets', 't');

        $this->aplicarFiltrosDeLista($qb, $filtros);

        return (int) $qb->fetchOne();
    }

    /**
     * Inbound messages newer than the ticket's `last_read_at` (or all of them
     * when it is null), per conversation. Conversations without unread
     * messages are absent from the result.
     *
     * @param int[] $conversacionIds
     * @return list<array{conversation_id: mixed, cnt: mixed}>
     */
    public function countEntrantesNoLeidosByConversacion(array $conversacionIds): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('m2.conversation_id', 'COUNT(*) AS cnt')
            ->from('messages', 'm2')
            ->innerJoin('m2', 'tickets', 't2', 't2.conversation_id = m2.conversation_id')
            ->where('m2.conversation_id IN (:conversationIds)')
            ->andWhere("m2.direction = 'inbound'")
            ->andWhere("m2.created_at > COALESCE(t2.last_read_at, '1970-01-01 00:00:00')")
            ->groupBy('m2.conversation_id')
            ->setParameter('conversationIds', $conversacionIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
    }

    /**
     * Whether the agent has a new, active or waiting ticket in the workspace
     * that was never read or has an inbound message newer than its last read
     * (`has_pending` in `GET /api/me/workspaces`).
     */
    public function hasPendientesDeAgente(string $phoneNumberId, int $agenteId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('1')
            ->from('tickets', 't')
            ->innerJoin('t', 'conversations', 'conv', 't.conversation_id = conv.id')
            ->where('t.phone_number_id = :phone')
            ->andWhere('t.assigned_agent_id = :agentId')
            ->andWhere('t.status IN (:statuses)')
            ->andWhere('t.last_read_at IS NULL OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = conv.id AND m.direction = :inbound AND m.created_at > t.last_read_at)')
            ->setParameter('phone', $phoneNumberId)
            ->setParameter('agentId', $agenteId)
            ->setParameter('statuses', ['new', 'active', 'waiting'], ArrayParameterType::STRING)
            ->setParameter('inbound', 'inbound')
            ->setMaxResults(1)
            ->fetchOne();
    }

    /**
     * The ticket detail row (`GetDetalleDeTicketQuery`): ticket, contact, the
     * unread inbound count and the content of the latest message, or false
     * when the ticket does not exist.
     *
     * @return array<string, mixed>|false
     */
    public function findFilaDeDetalle(int $id): array|false
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select(
                't.id AS ticket_id', 't.status', 't.priority', 't.assigned_agent_id AS owner_id',
                't.created_at AS opened_at', 't.first_response_at', 't.resolved_at', 't.updated_at',
                't.last_inbound_message_at',
                'c.id AS contact_id', 'c.phone AS contact_phone', 'c.name AS contact_name',
                'conv.id AS conv_id',
                "(SELECT COUNT(*) FROM messages m2 WHERE m2.conversation_id = conv.id AND m2.direction = 'inbound' AND m2.created_at > COALESCE(t.last_read_at, '1970-01-01 00:00:00')) AS unread_count",
                '(SELECT m3.content FROM messages m3 WHERE m3.conversation_id = conv.id ORDER BY m3.created_at DESC LIMIT 1) AS last_message',
            )
            ->from('tickets', 't')
            ->innerJoin('t', 'conversations', 'conv', 't.conversation_id = conv.id')
            ->innerJoin('conv', 'contacts', 'c', 'conv.contact_id = c.id')
            ->where('t.id = :ticketId')
            ->setParameter('ticketId', $id)
            ->fetchAssociative();
    }

    /**
     * The ticket's `conversation_id`: false when the ticket does not exist,
     * null when the column is null.
     */
    public function findConversacionId(int $id): int|false|null
    {
        $conversacionId = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('conversation_id')
            ->from('tickets')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchOne();

        if ($conversacionId === false || $conversacionId === null) {
            return $conversacionId;
        }

        return (int) $conversacionId;
    }

    /**
     * One keyset chunk of the CSV export (`ExportarTicketsCsvQuery`): tickets
     * matching the list filters, created within [`$from`, `$to`]
     * (`Y-m-d H:i:s`), with id greater than `$afterId`, by id ascending.
     *
     * @return list<array<string, mixed>>
     */
    public function findBloqueDeExportacion(FiltrosDeTicket $filtros, string $from, string $to, int $afterId, int $limit): array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select(
                't.id', 't.status', 't.priority', 't.assigned_agent_id', 't.phone_number_id',
                't.created_at', 't.first_response_at', 't.resolved_at', 't.closed_at',
                'TIMESTAMPDIFF(SECOND, t.created_at, t.first_response_at) AS first_response_seconds',
                'TIMESTAMPDIFF(SECOND, t.created_at, t.resolved_at) AS resolution_seconds',
                'c.phone AS contact_phone', 'c.name AS contact_name',
                'a.name AS agent_name',
            )
            ->from('tickets', 't')
            ->innerJoin('t', 'conversations', 'conv', 't.conversation_id = conv.id')
            ->innerJoin('t', 'contacts', 'c', 'conv.contact_id = c.id')
            ->leftJoin('t', 'agents', 'a', 't.assigned_agent_id = a.id');

        $this->aplicarFiltrosDeLista($qb, $filtros);

        return $qb->andWhere('t.created_at >= :from')
            ->andWhere('t.created_at <= :to')
            ->andWhere('t.id > :afterId')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('afterId', $afterId)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults($limit)
            ->fetchAllAssociative();
    }

    /**
     * Per contact, the highest id among its tickets that are neither closed
     * nor resolved, optionally limited to one workspace (`phone_number_id`).
     * Contacts without such a ticket are absent from the result.
     *
     * @param int[] $contactoIds
     * @return list<array{contact_id: mixed, ticket_id: mixed}>
     */
    public function findUltimosTicketIdsAbiertosByContacto(array $contactoIds, ?string $phoneNumberId): array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('t.contact_id', 'MAX(t.id) AS ticket_id')
            ->from('tickets', 't')
            ->where('t.contact_id IN (:contactIds)')
            ->andWhere("t.status NOT IN ('closed', 'resolved')")
            ->setParameter('contactIds', $contactoIds, ArrayParameterType::INTEGER);

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phone_number_id = :phoneNumberId')
                ->setParameter('phoneNumberId', $phoneNumberId);
        }

        return $qb->groupBy('t.contact_id')->fetchAllAssociative();
    }

    /**
     * Adds the list filters as WHERE clauses on a query over `tickets t`,
     * in this order: assignment or mine, ownership scope, workspace, status,
     * one EXISTS per tag (AND semantics), team.
     */
    private function aplicarFiltrosDeLista(QueryBuilder $qb, FiltrosDeTicket $filtros): void
    {
        if ($filtros->asignacion === 'unassigned') {
            $qb->andWhere('t.assigned_agent_id IS NULL');
        } elseif ($filtros->asignacion === 'assigned') {
            $qb->andWhere('t.assigned_agent_id IS NOT NULL');
        } elseif ($filtros->misAgenteId !== null) {
            $qb->andWhere('t.assigned_agent_id = :mineAgentId')
                ->setParameter('mineAgentId', $filtros->misAgenteId);
        }

        if ($filtros->scopeAgenteId !== null) {
            $qb->andWhere('t.assigned_agent_id = :scopeAgentId')
                ->setParameter('scopeAgentId', $filtros->scopeAgenteId);
        }

        if ($filtros->phoneNumberId !== null) {
            $qb->andWhere('t.phone_number_id = :phoneNumberId')
                ->setParameter('phoneNumberId', $filtros->phoneNumberId);
        }

        if ($filtros->estados !== []) {
            $qb->andWhere('t.status IN (:statuses)')
                ->setParameter('statuses', $filtros->estados, ArrayParameterType::STRING);
        }

        foreach ($filtros->etiquetas as $i => $nombre) {
            $qb->andWhere("EXISTS (SELECT 1 FROM ticket_tags tt JOIN tags tg ON tg.id = tt.tag_id WHERE tt.ticket_id = t.id AND tg.name = :tag{$i})")
                ->setParameter("tag{$i}", $nombre);
        }

        if ($filtros->equipoId !== null) {
            $qb->andWhere('t.team_id = :teamId')
                ->setParameter('teamId', $filtros->equipoId);
        }
    }
}
