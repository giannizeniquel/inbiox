<?php

declare(strict_types=1);

namespace App\Reporte\Query;

use App\Ticket\Query\ListTicketsQuery;
use App\Ticket\Query\FiltrosDeTicketBuilder;

use App\Ticket\Repository\EtiquetaRepository;
use App\Ticket\Repository\TicketRepository;
use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * Streams the filtered ticket list as CSV rows into a `League\Csv\Writer`.
 *
 * Reuses `FiltrosDeTicketBuilder` for the shared filters (same semantics as
 * `ListTicketsQuery`); `TicketRepository::findExportChunk()` adds a
 * `t.created_at` date-range bound and a keyset cursor
 * (`t.id > :afterId ORDER BY t.id ASC LIMIT 500`) so results
 * are walked in bounded-memory chunks with no gaps/duplicates — see
 * `design.md`, "keyset chunking, not an unbuffered PDO connection".
 */
class ExportarTicketsCsvQuery
{
    public const CHUNK_SIZE = 500;

    /** Column order — MUST match the header row and the spec's column contract. */
    public const COLUMNAS = [
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
    private const COLUMNAS_A_ESCAPAR = ['contact_name', 'assigned_agent', 'tags'];

    public function __construct(
        private FiltrosDeTicketBuilder $filtrosDeTicketBuilder,
        private TicketRepository $ticketRepository,
        private EtiquetaRepository $etiquetaRepository,
    ) {}

    /**
     * @param string|null $agenteId 'mine'-style agent id filter (unused directly; kept for parity with ListTicketsQuery's $filter/$agenteId pair)
     * @param string|null $estado Comma-separated status filter
     * @param string|null $etiqueta Comma-separated tag names (AND semantics, max 5)
     * @param string|null $phoneNumberId Workspace scope
     * @param int|null $equipoId Single-select team filter, composable with $etiqueta
     *
     * @return int total number of data rows written
     */
    public function stream(
        Writer $writer,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $agenteId = null,
        ?string $estado = null,
        ?string $etiqueta = null,
        ?string $phoneNumberId = null,
        ?int $equipoId = null,
    ): int {
        $writer->insertOne(self::COLUMNAS);

        $escapeFormula = new EscapeFormula();

        $filters = $this->filtrosDeTicketBuilder->build(
            filtro: $agenteId ? 'mine' : 'all',
            agenteId: $agenteId,
            estado: $estado,
            phoneNumberId: $phoneNumberId,
            etiqueta: $etiqueta,
            equipoId: $equipoId,
        );
        $fromBound = $from->format('Y-m-d H:i:s');
        $toBound = $to->format('Y-m-d H:i:s');

        $lastId = 0;
        $totalRows = 0;

        do {
            $rows = $this->ticketRepository->findBloqueDeExportacion($filters, $fromBound, $toBound, $lastId, self::CHUNK_SIZE);
            $chunkCount = count($rows);

            if ($chunkCount === 0) {
                break;
            }

            $ticketIds = array_map(fn($row) => (int) $row['id'], $rows);
            $etiquetasPorTicket = $this->fetchEtiquetasPorTicket($ticketIds);

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
                    'tags' => implode(';', $etiquetasPorTicket[$ticketId] ?? []),
                    'phone_number_id' => $row['phone_number_id'] ?? '',
                ];

                $escapable = array_intersect_key($record, array_flip(self::COLUMNAS_A_ESCAPAR));
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
    private function fetchEtiquetasPorTicket(array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        $rows = $this->etiquetaRepository->findNombresByTicketIds($ticketIds);

        $etiquetasPorTicket = [];
        foreach ($rows as $row) {
            $etiquetasPorTicket[(int) $row['ticket_id']][] = $row['name'];
        }

        return $etiquetasPorTicket;
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
