<?php

namespace App\Ticket\Query;

use App\Ticket\Repository\TicketRepository;

class GetTicketDetailQuery
{
    public function __construct(
        private TicketRepository $ticketRepository,
    ) {}

    public function execute(int $ticketId): ?TicketDetailDTO
    {
        $row = $this->ticketRepository->findDetailRow($ticketId);

        if (!$row) {
            return null;
        }

        $contact = [
            'id' => (int) $row['contact_id'],
            'phone' => $row['contact_phone'],
            'name' => $row['contact_name'],
        ];

        $owner = null;
        if ($row['owner_id'] !== null) {
            $owner = [
                'id' => $row['owner_id'],
                'name' => null, // Placeholder - would need User entity
            ];
        }

        $timestamps = [
            'opened_at' => (new \DateTimeImmutable($row['opened_at']))->format('Y-m-d\TH:i:s\Z'),
            'assigned_at' => $row['first_response_at'] 
                ? (new \DateTimeImmutable($row['first_response_at']))->format('Y-m-d\TH:i:s\Z')
                : null,
            'resolved_at' => $row['resolved_at']
                ? (new \DateTimeImmutable($row['resolved_at']))->format('Y-m-d\TH:i:s\Z')
                : null,
            'closed_at' => null, // Will be populated when closed_at field exists
            'last_inbound_message_at' => $row['last_inbound_message_at']
                ? (new \DateTimeImmutable($row['last_inbound_message_at']))->format('Y-m-d\TH:i:s\Z')
                : null,
        ];

        return new TicketDetailDTO(
            ticketId: (int) $row['ticket_id'],
            status: $row['status'],
            priority: $row['priority'],
            contact: $contact,
            owner: $owner,
            timestamps: $timestamps,
            metrics: ['unread_count' => (int) ($row['unread_count'] ?? 0)],
            lastMessage: $row['last_message'] ?? null,
        );
    }
}
