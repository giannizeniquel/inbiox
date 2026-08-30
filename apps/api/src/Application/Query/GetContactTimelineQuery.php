<?php

namespace App\Application\Query;

use App\Application\DTO\TimelineEventDTO;
use App\Domain\Event\TicketEventType;
use Doctrine\ORM\EntityManagerInterface;

class GetContactTimelineQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Execute the timeline query for a given contact.
     *
     * @return array{events: TimelineEventDTO[], cursor: string|null, hasMore: bool}
     */
    public function execute(int $contactId, ?string $before, int $limit, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();

        // Fetch limit+1 to determine hasMore
        $limitPlusOne = $limit + 1;

        $sql = $this->buildSql($before, $phoneNumberId);
        $params = $this->buildParams($contactId, $before, $limitPlusOne, $phoneNumberId);
        $types = $this->buildTypes($before, $phoneNumberId);

        $stmt = $conn->executeQuery($sql, $params, $types);
        $rows = $stmt->fetchAllAssociative();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $events = array_map(
            fn (array $row): TimelineEventDTO => new TimelineEventDTO(
                type: $row['type'],
                occurredAt: $row['occurred_at'],
                content: $row['content'],
                ticketId: $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null,
                ticketStatus: $row['ticket_status'],
                assignedTo: $row['assigned_to'],
                agentId: $row['agent_id'] !== null ? (int) $row['agent_id'] : null,
                direction: $row['direction'],
            ),
            $rows,
        );

        // Cursor: ISO 8601 of the oldest event in the batch
        $cursor = null;
        if (!empty($rows)) {
            $lastRow = $rows[count($rows) - 1];
            $cursor = $lastRow['occurred_at'];
        }

        return [
            'events' => $events,
            'cursor' => $cursor,
            'hasMore' => $hasMore,
        ];
    }

    private function buildSql(?string $before, ?string $phoneNumberId): string
    {
        $outboxWhere = "t.contact_id = :contact_id\n              AND oe.aggregate_type = 'Ticket'\n              AND (oe.event_type LIKE '%TicketCreated%' OR oe.event_type LIKE '%TicketUpdated%')";
        $assignmentWhere = 't.contact_id = :contact_id';
        // D6 allow-list: only the WhatsApp 24h window-compliance event types
        // plus the six manual closure/resolution audit types (D2) are
        // surfaced here. Reading every ticket_events type would silently
        // add tag_added/tag_removed/auto_reopened/auto_closed to every
        // contact timeline — an out-of-scope behavior change.
        $allowListedTypes = [
            TicketEventType::WINDOW_EXPIRED_CLOSED,
            TicketEventType::WINDOW_REOPENED,
            TicketEventType::MANUAL_RESOLVED_OWNER,
            TicketEventType::MANUAL_RESOLVED_OVERRIDE,
            TicketEventType::MANUAL_RESOLVED_UNOWNED,
            TicketEventType::MANUAL_CLOSED_OWNER,
            TicketEventType::MANUAL_CLOSED_OVERRIDE,
            TicketEventType::MANUAL_CLOSED_UNOWNED,
        ];
        $ticketEventWhere = "t.contact_id = :contact_id\n              AND te.type IN ('" . implode("', '", $allowListedTypes) . "')";

        if ($before !== null) {
            $outboxWhere .= "\n            AND oe.created_at < :before_date";
            $assignmentWhere .= "\n            AND a.assigned_at < :before_date";
            $ticketEventWhere .= "\n            AND te.created_at < :before_date";
        }

        if ($phoneNumberId !== null) {
            $outboxWhere .= "\n            AND t.phone_number_id = :phone_number_id";
            $assignmentWhere .= "\n            AND t.phone_number_id = :phone_number_id";
            $ticketEventWhere .= "\n            AND t.phone_number_id = :phone_number_id";
        }

        $windowExpiredClosedType = TicketEventType::WINDOW_EXPIRED_CLOSED;
        $windowReopenedType = TicketEventType::WINDOW_REOPENED;
        $manualResolvedOwnerType = TicketEventType::MANUAL_RESOLVED_OWNER;
        $manualResolvedOverrideType = TicketEventType::MANUAL_RESOLVED_OVERRIDE;
        $manualResolvedUnownedType = TicketEventType::MANUAL_RESOLVED_UNOWNED;
        $manualClosedOwnerType = TicketEventType::MANUAL_CLOSED_OWNER;
        $manualClosedOverrideType = TicketEventType::MANUAL_CLOSED_OVERRIDE;
        $manualClosedUnownedType = TicketEventType::MANUAL_CLOSED_UNOWNED;

        return <<<SQL
        SELECT type, occurred_at, content, ticket_id, ticket_status, assigned_to, agent_id, direction
        FROM (
            -- Ticket lifecycle events (created, status_changed)
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
            WHERE {$outboxWhere}

            UNION ALL

            -- Agent assignments
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
            WHERE {$assignmentWhere}

            UNION ALL

            -- WhatsApp 24h conversation-window closure/reopen audit, plus
            -- manual closure/resolution audit trail (D6 allow-list)
            SELECT
                CASE te.type
                    WHEN '{$windowExpiredClosedType}' THEN 'ticket.window_expired_closed'
                    WHEN '{$windowReopenedType}' THEN 'ticket.window_reopened'
                    WHEN '{$manualResolvedOwnerType}' THEN 'ticket.manual_resolved_owner'
                    WHEN '{$manualResolvedOverrideType}' THEN 'ticket.manual_resolved_override'
                    WHEN '{$manualResolvedUnownedType}' THEN 'ticket.manual_resolved_unowned'
                    WHEN '{$manualClosedOwnerType}' THEN 'ticket.manual_closed_owner'
                    WHEN '{$manualClosedOverrideType}' THEN 'ticket.manual_closed_override'
                    WHEN '{$manualClosedUnownedType}' THEN 'ticket.manual_closed_unowned'
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
            WHERE {$ticketEventWhere}
        ) AS timeline
        ORDER BY occurred_at DESC
        LIMIT :limit
        SQL;
    }

    private function buildParams(int $contactId, ?string $before, int $limit, ?string $phoneNumberId): array
    {
        $params = [
            'contact_id' => $contactId,
            'limit' => $limit,
        ];

        if ($before !== null) {
            $params['before_date'] = $before;
        }

        if ($phoneNumberId !== null) {
            $params['phone_number_id'] = $phoneNumberId;
        }

        return $params;
    }

    private function buildTypes(?string $before, ?string $phoneNumberId): array
    {
        $types = [
            'contact_id' => \Doctrine\DBAL\Types\Types::INTEGER,
            'limit' => \Doctrine\DBAL\Types\Types::INTEGER,
        ];

        if ($before !== null) {
            $types['before_date'] = \Doctrine\DBAL\Types\Types::STRING;
        }

        if ($phoneNumberId !== null) {
            $types['phone_number_id'] = \Doctrine\DBAL\Types\Types::STRING;
        }

        return $types;
    }
}
