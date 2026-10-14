<?php

namespace App\Ticket\Query;

use App\Disponibilidad\AgentAvailabilityService;
use App\Agente\Agent;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ListTicketsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AgentAvailabilityService $agentAvailabilityService,
        private TicketRepository $ticketRepository,
        private TicketFilterBuilder $ticketFilterBuilder = new TicketFilterBuilder(),
    ) {}

    /**
     * @param int $page Page number (1-indexed)
     * @param int $limit Items per page (max 100)
     * @param string $filter 'all', 'unassigned', 'assigned', or 'mine'
     * @param string|null $agentId Agent ID for 'mine' filter (DEPRECATED: use scopeAgentId instead)
     * @param string|null $status Comma-separated status filter
     * @param string|null $search Search query (not implemented)
     * @param string $sort Sort order
     * @param int|null $scopeAgentId Ownership scope: forces WHERE assigned_agent_id = scopeAgentId
     *                                Used by InboxController to restrict AGENT to own tickets.
     *                                null = no scope restriction (SUPERVISOR/ADMIN).
     * @param string|null $phoneNumberId Workspace scope: filters by ticket phone_number_id.
     *                                   Used by InboxController via WorkspaceScope.
     *                                   null = no workspace filter (SUPERVISOR/ADMIN).
     * @param string|null $tag Comma-separated tag names (AND semantics, max 5).
     *                         Each matched ticket must carry ALL specified tags.
     * @param int|null $teamId Single-select team filter, composable with $tag.
     */
    public function execute(
        int $page = 1,
        int $limit = 20,
        string $filter = 'all',
        ?string $agentId = null,
        ?string $status = null,
        ?string $search = null,
        string $sort = 'last_message_desc',
        ?int $scopeAgentId = null,
        ?string $phoneNumberId = null,
        ?string $tag = null,
        ?int $teamId = null,
    ): array {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;
        
        $conn = $this->entityManager->getConnection();

        [$where, $params] = $this->ticketFilterBuilder->build(
            filter: $filter,
            agentId: $agentId,
            status: $status,
            scopeAgentId: $scopeAgentId,
            phoneNumberId: $phoneNumberId,
            tag: $tag,
            teamId: $teamId,
        );

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT t.id, t.status, t.priority, t.assigned_agent_id, t.created_at, t.updated_at,
                       t.last_inbound_message_at,
                       c.phone, c.name as contact_name, conv.id as conv_id,
                       a.name as agent_name, a.max_active_tickets,
                       (SELECT COUNT(*) FROM tickets t2
                        WHERE t2.assigned_agent_id = a.id
                        AND t2.status IN ('new', 'active', 'waiting')) as current_active_tickets
                FROM tickets t
                JOIN conversations conv ON t.conversation_id = conv.id
                JOIN contacts c ON conv.contact_id = c.id
                LEFT JOIN agents a ON t.assigned_agent_id = a.id
                $whereClause
                ORDER BY t.updated_at DESC, t.id DESC
                LIMIT $limit OFFSET $offset";
        
        $stmt = $conn->executeQuery($sql, $params);
        $results = $stmt->fetchAllAssociative();
        
        $countSql = "SELECT COUNT(*) FROM tickets t $whereClause";
        $countStmt = $conn->executeQuery($countSql, $params);
        $total = (int) $countStmt->fetchOne();

        // Compute unread counts post-query: count inbound messages after ticket.last_read_at
        $unreadCounts = [];
        if (!empty($results)) {
            $convIds = array_unique(array_column($results, 'conv_id'));
            if (!empty($convIds)) {
                $convPlaceholders = implode(',', array_fill(0, count($convIds), '?'));

                $unreadSql = "SELECT m2.conversation_id, COUNT(*) as cnt
                    FROM messages m2
                    JOIN tickets t2 ON t2.conversation_id = m2.conversation_id
                    WHERE m2.conversation_id IN ($convPlaceholders)
                    AND m2.direction = 'inbound'
                    AND m2.created_at > COALESCE(t2.last_read_at, '1970-01-01 00:00:00')
                    GROUP BY m2.conversation_id";

                $unreadParams = array_map(fn($id) => (int) $id, array_values($convIds));

                $unreadRows = $conn->executeQuery($unreadSql, $unreadParams)->fetchAllAssociative();
                foreach ($unreadRows as $row) {
                    $unreadCounts[(int) $row['conversation_id']] = (int) $row['cnt'];
                }
            }
        }

        // Preload agent entities for is_in_shift evaluation
        $now = new \DateTimeImmutable();
        $agentIds = array_unique(array_filter(array_map(fn($r) => $r['assigned_agent_id'] ? (int) $r['assigned_agent_id'] : null, $results)));
        $agentEntities = [];
        if (!empty($agentIds)) {
            $agentEntities = $this->entityManager
                ->createQueryBuilder()
                ->select('a')
                ->from(Agent::class, 'a')
                ->where('a.id IN (:ids)')
                ->setParameter('ids', $agentIds)
                ->getQuery()
                ->getResult();
        }
        /** @var array<int, Agent> $agentMap */
        $agentMap = [];
        foreach ($agentEntities as $agent) {
            $agentMap[$agent->getId()] = $agent;
        }
        
        $items = array_map(function ($row) use ($agentMap, $now, $unreadCounts) {
            $owner = null;
            if (!empty($row['assigned_agent_id'])) {
                $agentId = (int) $row['assigned_agent_id'];
                $agentEntity = $agentMap[$agentId] ?? null;
                
                $maxCapacity = (int) ($row['max_active_tickets'] ?? 10);
                $currentLoad = (int) ($row['current_active_tickets'] ?? 0);
                
                $isInShift = false;
                if ($agentEntity !== null) {
                    $isInShift = $this->agentAvailabilityService->isInShift($agentEntity, $now);
                }
                
                $owner = [
                    'id' => $agentId,
                    'name' => $row['agent_name'],
                    'is_in_shift' => $isInShift,
                    'current_load' => $currentLoad,
                    'max_capacity' => $maxCapacity,
                ];
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
                metrics: ['unread_count' => $unreadCounts[(int) ($row['conv_id'] ?? 0)] ?? 0],
                timestamps: [
                    'opened_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'last_inbound_message_at' => $row['last_inbound_message_at'] ?? null,
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
