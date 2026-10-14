<?php

namespace App\Ticket\Query;

use Doctrine\ORM\EntityManagerInterface;

class GetTicketDetailQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function execute(int $ticketId): ?TicketDetailDTO
    {
        $sql = <<<SQL
            SELECT 
                t.id as ticket_id,
                t.status,
                t.priority,
                t.assigned_agent_id as owner_id,
                t.created_at as opened_at,
                t.first_response_at,
                t.resolved_at,
                t.updated_at,
                t.last_inbound_message_at,
                c.id as contact_id,
                c.phone as contact_phone,
                c.name as contact_name,
                conv.id as conv_id,
                (SELECT COUNT(*) FROM messages m2
                 WHERE m2.conversation_id = conv.id
                 AND m2.direction = 'inbound'
                 AND m2.created_at > COALESCE(t.last_read_at, '1970-01-01 00:00:00')
                ) as unread_count,
                (SELECT m3.content FROM messages m3
                 WHERE m3.conversation_id = conv.id
                 ORDER BY m3.created_at DESC LIMIT 1
                ) as last_message
            FROM tickets t
            INNER JOIN conversations conv ON t.conversation_id = conv.id
            INNER JOIN contacts c ON conv.contact_id = c.id
            WHERE t.id = :ticketId
        SQL;

        $stmt = $this->entityManager->getConnection()->executeQuery($sql, [
            'ticketId' => $ticketId,
        ]);

        $row = $stmt->fetchAssociative();

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
