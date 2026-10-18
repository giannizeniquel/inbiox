<?php

namespace App\Contacto\Query;

use App\Contacto\Repository\ContactoRepository;

class GetLineaDeTiempoDeContactoQuery
{
    public function __construct(
        private ContactoRepository $contactRepository,
    ) {}

    /**
     * Execute the timeline query for a given contact.
     *
     * @return array{events: EventoDeLineaDeTiempoDTO[], cursor: string|null, hasMore: bool}
     */
    public function execute(int $contactId, ?string $before, int $limit, ?string $phoneNumberId = null): array
    {
        // Fetch limit+1 to determine hasMore
        $rows = $this->contactRepository->findFilasDeLineaDeTiempo($contactId, $before, $limit + 1, $phoneNumberId);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $events = array_map(
            fn (array $row): EventoDeLineaDeTiempoDTO => new EventoDeLineaDeTiempoDTO(
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
}
