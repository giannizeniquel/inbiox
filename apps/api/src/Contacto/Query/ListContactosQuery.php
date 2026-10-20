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

        // Los contactos se comparten entre espacios de trabajo — solo las conversaciones/tickets
        // están segregados. No hay filtro por phone_number_id en la lista de contactos.
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
                    'creadoAt' => $row['created_at'],
                    'actualizadoAt' => $row['updated_at'],
                ],
            );
        }, $results);

        // Resuelve ticketActivoId en una segunda pasada para evitar problemas con el orden de los parámetros SQL
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
     * Resuelve el ID del ticket activo (no cerrado) de cada contacto.
     * Se hace en una query separada después de conocer la página de contactos.
     */
    private function agregarTicketsActivos(array $items, array $contactoIds, ?string $phoneNumberId): void
    {
        if (empty($contactoIds)) return;

        $rows = $this->ticketRepository->findUltimosTicketIdsAbiertosByContacto($contactoIds, $phoneNumberId);

        // Arma el mapa de búsqueda: contact_id → ticket_id
        $mapaDeTickets = [];
        foreach ($rows as $row) {
            $mapaDeTickets[(int) $row['contact_id']] = (int) $row['ticket_id'];
        }

        // Completa los DTOs
        foreach ($items as $dto) {
            $dto->ticketActivoId = $mapaDeTickets[$dto->id] ?? null;
        }
    }
}