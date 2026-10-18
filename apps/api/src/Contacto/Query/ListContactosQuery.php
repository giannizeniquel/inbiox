<?php

namespace App\Contacto\Query;

use App\Contacto\Repository\ContactoRepository;
use App\Ticket\Repository\TicketRepository;

class ListContactosQuery
{
    public function __construct(
        private ContactoRepository $contactoRepository,
        private TicketRepository $ticketRepository,
    ) {}

    public function execute(
        int $page = 1,
        int $limit = 20,
        ?string $search = null,
        ?string $estado = null,
        ?string $canal = null,
        ?int $responsableId = null,
        ?string $email = null,
        ?string $phoneNumberId = null,
    ): array {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;

        // Contacts are shared across workspaces — only conversations/tickets
        // are segregated. No phone_number_id filter on the contact list.
        $results = $this->contactoRepository->findPaginaDeLista($search, $estado, $canal, $responsableId, $email, $limit, $offset);
        $total = $this->contactoRepository->countLista($search, $estado, $canal, $responsableId, $email);

        $items = array_map(function ($row) {
            return new ContactoDeListaDTO(
                id: (int) $row['id'],
                telefono: $row['phone'],
                nombre: $row['name'],
                estado: $row['status'],
                canal: $row['channel'],
                responsableId: $row['owner_id'] ? (int) $row['owner_id'] : null,
                email: $row['email'],
                marcasDeTiempo: [
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ],
            );
        }, $results);

        // Resolve activeTicketId in a second pass to avoid SQL parameter ordering issues
        if (!empty($items)) {
            $contactoIds = array_map(fn($dto) => $dto->id, $items);
            $this->agregarTicketsActivos($items, $contactoIds, $phoneNumberId);
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
    private function agregarTicketsActivos(array $items, array $contactoIds, ?string $phoneNumberId): void
    {
        if (empty($contactoIds)) return;

        $rows = $this->ticketRepository->findUltimosTicketIdsAbiertosByContacto($contactoIds, $phoneNumberId);

        // Build lookup: contact_id → ticket_id
        $mapaDeTickets = [];
        foreach ($rows as $row) {
            $mapaDeTickets[(int) $row['contact_id']] = (int) $row['ticket_id'];
        }

        // Patch DTOs
        foreach ($items as $dto) {
            $dto->ticketActivoId = $mapaDeTickets[$dto->id] ?? null;
        }
    }
}