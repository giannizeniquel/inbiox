<?php

declare(strict_types=1);

namespace App\Application\Query;

use Doctrine\ORM\EntityManagerInterface;
use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * Streams the filtered ticket list as CSV rows into a `League\Csv\Writer`.
 *
 * Reuses `TicketFilterBuilder` for the shared WHERE/params (same semantics
 * as `ListTicketsQuery`), then appends a `t.created_at` date-range bound and
 * a keyset cursor (`t.id > :lastId ORDER BY t.id ASC LIMIT 500`) so results
 * are walked in bounded-memory chunks with no gaps/duplicates — see
 * `design.md`, "keyset chunking, not an unbuffered PDO connection".
 */
class ExportTicketsCsvQuery
{
    public const CHUNK_SIZE = 500;

    /** Column order — MUST match the header row and the spec's column contract. */
    public const COLUMNS = [
        'ticket_id',
        'contact_name',
        'contact_phone',
        'assigned_agent',
        'status',
        'priority',
        'created_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'first_response_seconds',
        'resolution_seconds',
        'tags',
        'phone_number_id',
    ];

    /** Only free-text columns get formula-injection escaping; phone is excluded on purpose. */
    private const ESCAPABLE_COLUMNS = ['contact_name', 'assigned_agent', 'tags'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TicketFilterBuilder $ticketFilterBuilder,
    ) {}

    /**
     * @param string|null $agentId 'mine'-style agent id filter (unused directly; kept for parity with ListTicketsQuery's $filter/$agentId pair)
     * @param string|null $status Comma-separated status filter
     * @param string|null $tag Comma-separated tag names (AND semantics, max 5)
     * @param string|null $phoneNumberId Workspace scope
     * @param int|null $teamId Single-select team filter, composable with $tag
     *
     * @return int total number of data rows written
     */
    public function stream(
        Writer $writer,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $agentId = null,
        ?string $status = null,
        ?string $tag = null,
        ?string $phoneNumberId = null,
        ?int $teamId = null,
    ): int {
        $writer->insertOne(self::COLUMNS);

        $escapeFormula = new EscapeFormula();

        [$baseWhere, $baseParams] = $this->ticketFilterBuilder->build(
            filter: $agentId ? 'mine' : 'all',
            agentId: $agentId,
            status: $status,
            phoneNumberId: $phoneNumberId,
            tag: $tag,
            teamId: $teamId,
        );

        $baseWhere[] = 't.created_at >= ?';
        $baseParams[] = $from->format('Y-m-d H:i:s');
        $baseWhere[] = 't.created_at <= ?';
        $baseParams[] = $to->format('Y-m-d H:i:s');

        $conn = $this->entityManager->getConnection();
        $lastId = 0;
        $totalRows = 0;

        do {
            $chunkWhere = $baseWhere;
            $chunkParams = $baseParams;
            $chunkWhere[] = 't.id > ?';
            $chunkParams[] = $lastId;

            $whereClause = 'WHERE ' . implode(' AND ', $chunkWhere);

            $sql = "SELECT t.id, t.status, t.priority, t.assigned_agent_id, t.phone_number_id,
                           t.created_at, t.first_response_at, t.resolved_at, t.closed_at,
                           TIMESTAMPDIFF(SECOND, t.created_at, t.first_response_at) AS first_response_seconds,
                           TIMESTAMPDIFF(SECOND, t.created_at, t.resolved_at) AS resolution_seconds,
                           c.phone AS contact_phone, c.name AS contact_name,
                           a.name AS agent_name
                    FROM tickets t
                    JOIN conversations conv ON t.conversation_id = conv.id
                    JOIN contacts c ON conv.contact_id = c.id
                    LEFT JOIN agents a ON t.assigned_agent_id = a.id
                    $whereClause
                    ORDER BY t.id ASC
                    LIMIT " . self::CHUNK_SIZE;

            $rows = $conn->executeQuery($sql, $chunkParams)->fetchAllAssociative();
            $chunkCount = count($rows);

            if ($chunkCount === 0) {
                break;
            }

            $ticketIds = array_map(fn($row) => (int) $row['id'], $rows);
            $tagsByTicket = $this->fetchTagsByTicket($conn, $ticketIds);

            foreach ($rows as $row) {
                $ticketId = (int) $row['id'];

                $record = [
                    'ticket_id' => $ticketId,
                    'contact_name' => $row['contact_name'] ?? '',
                    'contact_phone' => $row['contact_phone'] ?? '',
                    'assigned_agent' => $row['agent_name'] ?? '',
                    'status' => $row['status'],
                    'priority' => $row['priority'],
                    'created_at' => $this->toUtcIso($row['created_at']),
                    'first_response_at' => $this->toUtcIso($row['first_response_at']),
                    'resolved_at' => $this->toUtcIso($row['resolved_at']),
                    'closed_at' => $this->toUtcIso($row['closed_at']),
                    'first_response_seconds' => $row['first_response_seconds'] ?? '',
                    'resolution_seconds' => $row['resolution_seconds'] ?? '',
                    'tags' => implode(';', $tagsByTicket[$ticketId] ?? []),
                    'phone_number_id' => $row['phone_number_id'] ?? '',
                ];

                $escapable = array_intersect_key($record, array_flip(self::ESCAPABLE_COLUMNS));
                $record = array_merge($record, $escapeFormula->escapeRecord($escapable));

                $writer->insertOne(array_values($record));
                $totalRows++;
            }

            $lastId = (int) $rows[$chunkCount - 1]['id'];
        } while ($chunkCount === self::CHUNK_SIZE);

        return $totalRows;
    }

    /**
     * Per-chunk correlated tag lookup, mirroring `ListTicketsQuery`'s existing
     * chunked unread-count lookup pattern (design.md, Data Flow).
     *
     * @param list<int> $ticketIds
     * @return array<int, list<string>> ticket id => ordered tag names
     */
    private function fetchTagsByTicket(\Doctrine\DBAL\Connection $conn, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $sql = "SELECT tt.ticket_id, tg.name
                FROM ticket_tags tt
                JOIN tags tg ON tg.id = tt.tag_id
                WHERE tt.ticket_id IN ($placeholders)
                ORDER BY tt.ticket_id ASC, tt.id ASC";

        $rows = $conn->executeQuery($sql, $ticketIds)->fetchAllAssociative();

        $tagsByTicket = [];
        foreach ($rows as $row) {
            $tagsByTicket[(int) $row['ticket_id']][] = $row['name'];
        }

        return $tagsByTicket;
    }

    private function toUtcIso(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        $dt = $value instanceof \DateTimeImmutable ? $value : new \DateTimeImmutable((string) $value);

        return $dt->format('Y-m-d\TH:i:s\Z');
    }
}
