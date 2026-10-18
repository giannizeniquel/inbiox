<?php

namespace App\Contacto\Query;

use App\Contacto\Repository\ContactoRepository;
use App\Ticket\Repository\TicketRepository;

class ListContactosQuery
{
    public function __construct(
        private ContactoRepository $contactRepository,
        private TicketRepository $ticketRepository,
    ) {}

    public function execute(
        int $page = 1,
        int $limit = 20,
        ?string $search = null,
        ?string $status = null,
        ?string $channel = null,
        ?int $ownerId = null,
        ?string $email = null,
        ?string $phoneNumberId = null,
    ): array {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;

        // Contacts are shared across workspaces — only conversations/tickets
        // are segregated. No phone_number_id filter on the contact list.
        $results = $this->contactRepository->findListPage($search, $status, $channel, $ownerId, $email, $limit, $offset);
        $total = $this->contactRepository->countList($search, $status, $channel, $ownerId, $email);

        $items = array_map(function ($row) {
            return new ContactoDeListaDTO(
                id: (int) $row['id'],
                phone: $row['phone'],
                name: $row['name'],
                status: $row['status'],
                channel: $row['channel'],
                ownerId: $row['owner_id'] ? (int) $row['owner_id'] : null,
                email: $row['email'],
                timestamps: [
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ],
            );
        }, $results);

        // Resolve activeTicketId in a second pass to avoid SQL parameter ordering issues
        if (!empty($items)) {
            $contactIds = array_map(fn($dto) => $dto->id, $items);
            $this->resolveActiveTicketIds($items, $contactIds, $phoneNumberId);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Resolve the active (non-closed) ticket ID for each contact.
     * Done in a separate query after the contact page is known.
     */
    private function resolveActiveTicketIds(array $items, array $contactIds, ?string $phoneNumberId): void
    {
        if (empty($contactIds)) return;

        $rows = $this->ticketRepository->findUltimosTicketIdsAbiertosByContacto($contactIds, $phoneNumberId);

        // Build lookup: contact_id → ticket_id
        $ticketMap = [];
        foreach ($rows as $row) {
            $ticketMap[(int) $row['contact_id']] = (int) $row['ticket_id'];
        }

        // Patch DTOs
        foreach ($items as $dto) {
            $dto->activeTicketId = $ticketMap[$dto->id] ?? null;
        }
    }
}