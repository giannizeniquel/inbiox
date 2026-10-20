<?php

namespace App\Ticket\Query;

use App\Ticket\Repository\TicketRepository;

class GetDetalleDeTicketQuery
{
    public function __construct(
        private TicketRepository $ticketRepository,
    ) {}

    public function execute(int $ticketId): ?DetalleDeTicketDTO
    {
        $row = $this->ticketRepository->findFilaDeDetalle($ticketId);

        if (!$row) {
            return null;
        }

        $contacto = [
            'id' => (int) $row['contact_id'],
            'telefono' => $row['contact_phone'],
            'nombre' => $row['contact_name'],
        ];

        $responsable = null;
        if ($row['owner_id'] !== null) {
            $responsable = [
                'id' => $row['owner_id'],
                'nombre' => null, // Provisorio: necesitaría la entidad User
            ];
        }

        $marcasDeTiempo = [
            'abiertoAt' => (new \DateTimeImmutable($row['opened_at']))->format('Y-m-d\TH:i:s\Z'),
            'asignadoAt' => $row['first_response_at'] 
                ? (new \DateTimeImmutable($row['first_response_at']))->format('Y-m-d\TH:i:s\Z')
                : null,
            'resueltoAt' => $row['resolved_at']
                ? (new \DateTimeImmutable($row['resolved_at']))->format('Y-m-d\TH:i:s\Z')
                : null,
            'cerradoAt' => null, // Se completará cuando exista el campo closed_at
            'ultimoMensajeEntranteAt' => $row['last_inbound_message_at']
                ? (new \DateTimeImmutable($row['last_inbound_message_at']))->format('Y-m-d\TH:i:s\Z')
                : null,
        ];

        return new DetalleDeTicketDTO(
            ticketId: (int) $row['ticket_id'],
            estado: $row['status'],
            prioridad: $row['priority'],
            contacto: $contacto,
            responsable: $responsable,
            marcasDeTiempo: $marcasDeTiempo,
            metricas: ['cantidadDeNoLeidos' => (int) ($row['unread_count'] ?? 0)],
            ultimoMensaje: $row['last_message'] ?? null,
        );
    }
}
