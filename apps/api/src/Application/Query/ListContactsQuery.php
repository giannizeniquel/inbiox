<?php

namespace App\Application\Query;

use App\Application\DTO\ContactListItemDTO;
use Doctrine\ORM\EntityManagerInterface;

class ListContactsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
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

        $conn = $this->entityManager->getConnection();

        $where = [];
        $params = [];

        if ($search) {
            $where[] = '(c.name LIKE ? OR c.phone LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($status) {
            $validStatuses = ['active', 'inactive'];
            if (in_array($status, $validStatuses)) {
                $where[] = 'c.status = ?';
                $params[] = $status;
            }
        }

        if ($channel) {
            $validChannels = ['whatsapp', 'other'];
            if (in_array($channel, $validChannels)) {
                $where[] = 'c.channel = ?';
                $params[] = $channel;
            }
        }

        if ($ownerId) {
            $where[] = 'c.owner_id = ?';
            $params[] = $ownerId;
        }

        if ($email) {
            $where[] = 'c.email = ?';
            $params[] = $email;
        }

        // Contacts are shared across workspaces — only conversations/tickets
        // are segregated. No phone_number_id filter on the contact list.

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT c.id, c.phone, c.name, c.status, c.channel, c.owner_id, c.email, c.created_at, c.updated_at
                FROM contacts c
                $whereClause
                ORDER BY c.updated_at DESC, c.id DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $conn->executeQuery($sql, $params);
        $results = $stmt->fetchAllAssociative();

        $countSql = "SELECT COUNT(*) FROM contacts c $whereClause";
        $countStmt = $conn->executeQuery($countSql, $params);
        $total = (int) $countStmt->fetchOne();

        $items = array_map(function ($row) {
            return new ContactListItemDTO(
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
     * Done in a separate query to avoid SQL positional-parameter ordering issues
     * with the main query's dynamic WHERE clause.
     */
    private function resolveActiveTicketIds(array $items, array $contactIds, ?string $phoneNumberId): void
    {
        if (empty($contactIds)) return;

        $conn = $this->entityManager->getConnection();
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));

        $params = $contactIds;
        $types = array_fill(0, count($contactIds), \Doctrine\DBAL\Types\Types::INTEGER);

        $phoneFilter = '';
        if ($phoneNumberId !== null) {
            $phoneFilter = 'AND t.phone_number_id = ?';
            $params[] = $phoneNumberId;
            $types[] = \Doctrine\DBAL\Types\Types::STRING;
        }

        $sql = "SELECT t.contact_id, MAX(t.id) as ticket_id
                FROM tickets t
                WHERE t.contact_id IN ($placeholders)
                  AND t.status NOT IN ('closed', 'resolved')
                  $phoneFilter
                GROUP BY t.contact_id";

        $rows = $conn->executeQuery($sql, $params, $types)->fetchAllAssociative();

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