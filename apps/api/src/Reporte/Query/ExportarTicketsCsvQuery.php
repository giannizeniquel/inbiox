<?php

declare(strict_types=1);

namespace App\Reporte\Query;

use App\Ticket\Query\ListTicketsQuery;
use App\Ticket\Query\FiltrosDeTicketBuilder;
use App\Ticket\FiltroDeBandeja;

use App\Ticket\Repository\EtiquetaRepository;
use App\Ticket\Repository\TicketRepository;
use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * Emite en streaming la lista filtrada de tickets como filas CSV en un `League\Csv\Writer`.
 *
 * Reutiliza `FiltrosDeTicketBuilder` para los filtros compartidos (misma semántica que
 * `ListTicketsQuery`); `TicketRepository::findExportChunk()` agrega un límite
 * de rango de fechas sobre `t.created_at` y un cursor keyset
 * (`t.id > :afterId ORDER BY t.id ASC LIMIT 500`) para que los resultados
 * se recorran en bloques de memoria acotada, sin huecos ni duplicados; ver
 * `design.md`, "keyset chunking, not an unbuffered PDO connection".
 */
class ExportarTicketsCsvQuery
{
    public const CHUNK_SIZE = 500;

    /**
     * Fila de encabezados del CSV (contrato A.10): texto visible en español legible, en el
     * mismo orden que las claves internas de cada fila armada en `stream()`.
     */
    public const ENCABEZADOS = [
        'Ticket',
        'Contacto',
        'Teléfono del contacto',
        'Agente asignado',
        'Estado',
        'Prioridad',
        'Fecha de creación',
        'Fecha de primera respuesta',
        'Fecha de resolución',
        'Fecha de cierre',
        'Segundos hasta la primera respuesta',
        'Segundos hasta la resolución',
        'Etiquetas',
        'Número de WhatsApp (phone number id)',
    ];

    /** Solo las columnas de texto libre reciben escape contra inyección de fórmulas; el teléfono se excluye a propósito. */
    private const COLUMNAS_A_ESCAPAR = ['contact_name', 'assigned_agent', 'tags'];

    public function __construct(
        private FiltrosDeTicketBuilder $filtrosDeTicketBuilder,
        private TicketRepository $ticketRepository,
        private EtiquetaRepository $etiquetaRepository,
    ) {}

    /**
     * @param string|null $agenteId Filtro de id de agente al estilo 'mios' (no se usa directamente; se mantiene por paridad con el par $filter/$agenteId de ListTicketsQuery)
     * @param string|null $estado Filtro de estado separado por comas
     * @param string|null $etiqueta Nombres de etiquetas separados por comas (semántica AND, máximo 5)
     * @param string|null $phoneNumberId Alcance de espacio de trabajo
     * @param int|null $equipoId Filtro de equipo de selección única, combinable con $etiqueta
     *
     * @return int cantidad total de filas de datos escritas
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
        $writer->insertOne(self::ENCABEZADOS);

        $escapeFormula = new EscapeFormula();

        $filters = $this->filtrosDeTicketBuilder->build(
            filtro: ($agenteId ? FiltroDeBandeja::MIOS : FiltroDeBandeja::TODOS)->value,
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
     * Búsqueda correlacionada de etiquetas por bloque, que replica el patrón existente
     * de búsqueda por bloques del conteo de no leídos de `ListTicketsQuery` (design.md, Data Flow).
     *
     * @param list<int> $ticketIds
     * @return array<int, list<string>> id de ticket => nombres de etiquetas ordenados
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
