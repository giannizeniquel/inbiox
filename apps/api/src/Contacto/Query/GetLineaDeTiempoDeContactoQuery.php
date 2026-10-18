<?php

namespace App\Contacto\Query;

use App\Contacto\Repository\ContactoRepository;

class GetLineaDeTiempoDeContactoQuery
{
    public function __construct(
        private ContactoRepository $contactoRepository,
    ) {}

    /**
     * Execute the timeline query for a given contact.
     *
     * @return array{events: EventoDeLineaDeTiempoDTO[], cursor: string|null, hasMore: bool}
     */
    public function execute(int $contactoId, ?string $before, int $limit, ?string $phoneNumberId = null): array
    {
        // Fetch limit+1 to determine hasMore
        $rows = $this->contactoRepository->findFilasDeLineaDeTiempo($contactoId, $before, $limit + 1, $phoneNumberId);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $eventos = array_map(
            fn (array $row): EventoDeLineaDeTiempoDTO => new EventoDeLineaDeTiempoDTO(
                tipo: $row['type'],
                ocurridoAt: $row['occurred_at'],
                contenido: $row['content'],
                ticketId: $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null,
                estadoDeTicket: $row['ticket_status'],
                asignadoA: $row['assigned_to'],
                agenteId: $row['agent_id'] !== null ? (int) $row['agent_id'] : null,
                direccion: $row['direction'],
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
            'events' => $eventos,
            'cursor' => $cursor,
            'hasMore' => $hasMore,
        ];
    }
}
