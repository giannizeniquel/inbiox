<?php

namespace App\Application\Query;

use App\Application\DTO\TicketListItemDTO;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ListTicketsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function execute(
        int $page = 1,
        int $limit = 20,
        string $filter = 'all',
        ?string $agentId = null,
        ?string $status = null,
        ?string $search = null,
        string $sort = 'last_message_desc'
    ): array {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;
        
        $conn = $this->entityManager->getConnection();
        
        $where = [];
        $params = [];
        
        if ($filter === 'unassigned') {
            $where[] = 'assigned_agent_id IS NULL';
        } elseif ($filter === 'assigned') {
            $where[] = 'assigned_agent_id IS NOT NULL';
        } elseif ($filter === 'mine' && $agentId) {
            $where[] = 'assigned_agent_id = ?';
            $params[] = $agentId;
        }
        
        if ($status) {
            $statuses = array_filter(array_map('trim', explode(',', $status)), fn($s) => in_array($s, ['new','active','resolved','closed']));
            if ($statuses) {
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $where[] = "status IN ($placeholders)";
                $params = array_merge($params, $statuses);
            }
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT t.id, t.status, t.priority, t.assigned_agent_id, t.created_at, t.updated_at,
                       c.phone, c.name as contact_name, conv.id as conv_id
                FROM tickets t
                JOIN conversations conv ON t.conversation_id = conv.id
                JOIN contacts c ON conv.contact_id = c.id
                $whereClause
                ORDER BY t.updated_at DESC, t.id DESC
                LIMIT $limit OFFSET $offset";
        
        $stmt = $conn->executeQuery($sql, $params);
        $results = $stmt->fetchAllAssociative();
        
        $countSql = "SELECT COUNT(*) FROM tickets t $whereClause";
        $countStmt = $conn->executeQuery($countSql, $params);
        $total = (int) $countStmt->fetchOne();
        
        $items = array_map(function ($row) {
            $owner = null;
            if (!empty($row['assigned_agent_id'])) {
                $owner = ['id' => $row['assigned_agent_id'], 'name' => null];
            }
            
            return new TicketListItemDTO(
                ticketId: (int) $row['id'],
                status: $row['status'],
                priority: $row['priority'],
                contact: [
                    'id' => (int) ($row['conv_id'] ?? 1),
                    'phone' => $row['phone'] ?? '',
                    'name' => $row['contact_name'] ?? null,
                ],
                lastMessage: null,
                owner: $owner,
                metrics: ['unread_count' => 0],
                timestamps: [
                    'opened_at' => $row['created_at'],
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