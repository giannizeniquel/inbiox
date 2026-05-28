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

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }
}