<?php

namespace App\Repository;

use App\Application\Service\AgentAvailabilityService;
use App\Domain\Agent\Agent;
use App\Domain\Ticket\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for reporting queries.
 *
 * Provides aggregated metrics queries for tickets:
 * - First Response Time (FRT)
 * - Resolution Time (RT)
 * - Ticket counts by status
 * - Volume over time
 * - Agent performance
 *
 * All methods support optional ?int $agentId parameter for ownership scoping.
 */
class ReportingRepository extends ServiceEntityRepository
{
    private bool $isPostgres = false;
    
    public function __construct(
        ManagerRegistry $registry,
        private EntityManagerInterface $entityManager,
        private AgentAvailabilityService $agentAvailabilityService,
    ) {
        parent::__construct($registry, Ticket::class);
        
        // Detect database platform at construction time
        $this->detectPlatform();
    }
    
    private function detectPlatform(): void
    {
        try {
            $params = $this->entityManager->getConnection()->getParams();
            $url = $params['url'] ?? '';
            $this->isPostgres = str_contains($url, 'pgsql') || str_contains($url, 'postgres');
        } catch (\Throwable $e) {
            $this->isPostgres = false;
        }
    }

    /**
     * Get SQL expression for time difference between two columns.
     *
     * @param string $startColumn Start column (e.g., 't.created_at')
     * @param string $endColumn End column (e.g., 't.first_response_at')
     * @return string SQL expression for time difference in seconds
     */
    private function getTimeDiffExpression(string $startColumn, string $endColumn): string
    {
        if ($this->isPostgres) {
            return "EXTRACT(EPOCH FROM ({$endColumn} - {$startColumn}))";
        }
        return "TIMESTAMPDIFF(SECOND, {$startColumn}, {$endColumn})";
    }

    /**
     * Get First Response Time metrics.
     *
     * All values are in SECONDS (UTC-based).
     * Use _minutes convenience fields for UI.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array{avg_frt_seconds: float|null, min_frt_seconds: int|null, max_frt_seconds: int|null, avg_frt_minutes: float|null, min_frt_minutes: int|null, max_frt_minutes: int|null}
     */
    public function getFrtMetrics(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();
        
        $timeDiff = $this->getTimeDiffExpression('t.created_at', 't.first_response_at');
        
        $sql = "SELECT 
            AVG({$timeDiff}) as avg_frt,
            MIN({$timeDiff}) as min_frt,
            MAX({$timeDiff}) as max_frt
        FROM tickets t
        WHERE t.first_response_at IS NOT NULL
        AND t.created_at >= :from AND t.created_at < :to";
        
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        
        if ($agentId !== null) {
            $sql .= ' AND t.assigned_agent_id = :agentId';
            $params['agentId'] = $agentId;
        }

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }
        
        $result = $conn->fetchAssociative($sql, $params);
        
        $avgFrt = $result['avg_frt'] !== null ? (float) $result['avg_frt'] : null;
        $minFrt = $result['min_frt'] !== null ? (int) $result['min_frt'] : null;
        $maxFrt = $result['max_frt'] !== null ? (int) $result['max_frt'] : null;
        
        return [
            'avg_frt_seconds' => $avgFrt,
            'min_frt_seconds' => $minFrt,
            'max_frt_seconds' => $maxFrt,
            'avg_frt_minutes' => $avgFrt !== null ? round($avgFrt / 60, 2) : null,
            'min_frt_minutes' => $minFrt !== null ? (int) ($minFrt / 60) : null,
            'max_frt_minutes' => $maxFrt !== null ? (int) ($maxFrt / 60) : null,
        ];
    }

/**
     * Get Resolution Time metrics.
     *
     * All values are in SECONDS (UTC-based).
     * Use _minutes convenience fields for UI.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array{avg_rt_seconds: float|null, min_rt_seconds: int|null, max_rt_seconds: int|null, avg_rt_minutes: float|null, min_rt_minutes: int|null, max_rt_minutes: int|null}
     */
    public function getRtMetrics(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();
        
        $timeDiff = $this->getTimeDiffExpression('t.created_at', 't.resolved_at');
        
        $sql = "SELECT 
            AVG({$timeDiff}) as avg_rt,
            MIN({$timeDiff}) as min_rt,
            MAX({$timeDiff}) as max_rt
        FROM tickets t
        WHERE t.resolved_at IS NOT NULL
        AND t.created_at >= :from AND t.created_at < :to";
        
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        
        if ($agentId !== null) {
            $sql .= ' AND t.assigned_agent_id = :agentId';
            $params['agentId'] = $agentId;
        }

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }
        
        $result = $conn->fetchAssociative($sql, $params);
        
        $avgRt = $result['avg_rt'] !== null ? (float) $result['avg_rt'] : null;
        $minRt = $result['min_rt'] !== null ? (int) $result['min_rt'] : null;
        $maxRt = $result['max_rt'] !== null ? (int) $result['max_rt'] : null;
        
        return [
            'avg_rt_seconds' => $avgRt,
            'min_rt_seconds' => $minRt,
            'max_rt_seconds' => $maxRt,
            'avg_rt_minutes' => $avgRt !== null ? round($avgRt / 60, 2) : null,
            'min_rt_minutes' => $minRt !== null ? (int) ($minRt / 60) : null,
            'max_rt_minutes' => $maxRt !== null ? (int) ($maxRt / 60) : null,
        ];
    }

    /**
     * Get ticket counts grouped by status.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array{new: int, active: int, waiting: int, resolved: int, closed: int}
     */
    public function getCountsByStatus(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null): array
    {
        $qb = $this->createQueryBuilder('t');

        $qb->select('t.status', 'COUNT(t.id) as count')
            ->andWhere('t.createdAt >= :from AND t.createdAt < :to')
            ->groupBy('t.status')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($agentId !== null) {
            $qb->andWhere('t.assignedAgentId = :agentId')
               ->setParameter('agentId', $agentId);
        }

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phoneNumberId = :phoneNumberId')
               ->setParameter('phoneNumberId', $phoneNumberId);
        }

        $results = $qb->getQuery()->getResult();

        $counts = [
            'new' => 0,
            'active' => 0,
            'waiting' => 0,
            'resolved' => 0,
            'closed' => 0,
        ];

        foreach ($results as $row) {
            $status = $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['count'];
            }
        }

        return $counts;
    }

    /**
     * Get ticket volume grouped by day.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{date: string, tickets_created: int, tickets_resolved: int}>
     */
    public function getVolumeOverTime(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $agentClause = '';
        if ($agentId !== null) {
            $agentClause = ' AND t.assigned_agent_id = :agentId';
            $params['agentId'] = $agentId;
        }

        $workspaceClause = '';
        if ($phoneNumberId !== null) {
            $workspaceClause = ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }
        
        // Created tickets grouped by date
        $sqlCreated = 'SELECT DATE(t.created_at) as date, COUNT(t.id) as tickets_created
        FROM tickets t
        WHERE t.created_at >= :from AND t.created_at < :to' . $agentClause . $workspaceClause . '
        GROUP BY DATE(t.created_at)';
        
        $createdResults = $conn->fetchAllAssociative($sqlCreated, $params);

        // Resolved tickets grouped by date
        $sqlResolved = 'SELECT DATE(t.resolved_at) as date, COUNT(t.id) as tickets_resolved
        FROM tickets t
        WHERE t.resolved_at IS NOT NULL AND t.resolved_at >= :from AND t.resolved_at < :to' . $agentClause . $workspaceClause . '
        GROUP BY DATE(t.resolved_at)';
        
        $resolvedResults = $conn->fetchAllAssociative($sqlResolved, $params);

        // Merge results
        $volumeMap = [];
        foreach ($createdResults as $row) {
            $date = $row['date'];
            $volumeMap[$date] = [
                'date' => $date,
                'tickets_created' => (int) $row['tickets_created'],
                'tickets_resolved' => 0,
            ];
        }

        foreach ($resolvedResults as $row) {
            $date = $row['date'];
            if (!isset($volumeMap[$date])) {
                $volumeMap[$date] = [
                    'date' => $date,
                    'tickets_created' => 0,
                    'tickets_resolved' => 0,
                ];
            }
            $volumeMap[$date]['tickets_resolved'] = (int) $row['tickets_resolved'];
        }

        // Sort by date
        ksort($volumeMap);

        return array_values($volumeMap);
    }

    /**
     * Get agent performance metrics.
     *
     * All time values are in SECONDS (UTC-based).
     * Use _minutes convenience fields for UI.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by specific agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{agent_id: int, agent_name: string|null, tickets_assigned: int, tickets_resolved: int, current_active_tickets: int, max_active_tickets: int, current_load: float, is_in_shift: bool, avg_frt_seconds: float|null, avg_frt_minutes: float|null, avg_rt_seconds: float|null, avg_rt_minutes: float|null}>
     */
    public function getAgentPerformance(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();
        
        $frtTimeDiff = $this->getTimeDiffExpression('t.created_at', 't.first_response_at');
        $rtTimeDiff = $this->getTimeDiffExpression('t.created_at', 't.resolved_at');

        $workspaceSubClause = '';
        $workspaceJoinClause = '';
        if ($phoneNumberId !== null) {
            $workspaceSubClause = ' AND t2.phone_number_id = :phoneNumberId';
            $workspaceJoinClause = ' AND t.phone_number_id = :phoneNumberId';
        }
        
        $sql = "SELECT 
            a.id as agent_id,
            a.name as agent_name,
            a.max_active_tickets,
            COUNT(t.id) as tickets_assigned,
            SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as tickets_resolved,
            AVG(CASE WHEN t.first_response_at IS NOT NULL THEN {$frtTimeDiff} ELSE NULL END) as avg_frt,
            AVG(CASE WHEN t.resolved_at IS NOT NULL THEN {$rtTimeDiff} ELSE NULL END) as avg_rt,
            (SELECT COUNT(*) FROM tickets t2
             WHERE t2.assigned_agent_id = a.id
             AND t2.status IN ('new', 'active', 'waiting'){$workspaceSubClause}) as current_active_tickets
        FROM agents a
        LEFT JOIN tickets t ON t.assigned_agent_id = a.id
            AND t.created_at >= :from AND t.created_at < :to{$workspaceJoinClause}
        WHERE a.active = 1";
        
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        
        if ($agentId !== null) {
            $sql .= ' AND a.id = :agentId';
            $params['agentId'] = $agentId;
        }

        if ($phoneNumberId !== null) {
            $params['phoneNumberId'] = $phoneNumberId;
        }
        
        $sql .= " GROUP BY a.id, a.name, a.max_active_tickets ORDER BY a.id ASC";
        
        $results = $conn->fetchAllAssociative($sql, $params);
        
        // Preload agent entities for is_in_shift evaluation
        $now = new \DateTimeImmutable();
        $agentEntities = [];
        if (!empty($results)) {
            $agentIds = array_map(fn($r) => (int) $r['agent_id'], $results);
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
        
        $agentMetrics = [];
        foreach ($results as $row) {
            $agentId = (int) $row['agent_id'];
            $avgFrt = $row['avg_frt'] !== null ? (float) $row['avg_frt'] : null;
            $avgRt = $row['avg_rt'] !== null ? (float) $row['avg_rt'] : null;
            $maxActive = (int) ($row['max_active_tickets'] ?? 10);
            $currentActive = (int) ($row['current_active_tickets'] ?? 0);
            
            // Compute is_in_shift via AgentAvailabilityService (schedule only)
            $isInShift = false;
            if (isset($agentMap[$agentId])) {
                $isInShift = $this->agentAvailabilityService->isInShift($agentMap[$agentId], $now);
            }
            
            $agentMetrics[] = [
                'agent_id' => $agentId,
                'agent_name' => $row['agent_name'],
                'tickets_assigned' => (int) $row['tickets_assigned'],
                'tickets_resolved' => (int) $row['tickets_resolved'],
                'current_active_tickets' => $currentActive,
                'max_active_tickets' => $maxActive,
                'current_load' => $maxActive > 0 ? round($currentActive / $maxActive, 2) : 0.0,
                'is_in_shift' => $isInShift,
                'avg_frt_seconds' => $avgFrt,
                'avg_frt_minutes' => $avgFrt !== null ? round($avgFrt / 60, 2) : null,
                'avg_rt_seconds' => $avgRt,
                'avg_rt_minutes' => $avgRt !== null ? round($avgRt / 60, 2) : null,
            ];
        }
        
        return $agentMetrics;
    }

    /**
     * Get per-agent active ticket count vs max capacity.
     *
     * @param int|null $agentId Optional filter by specific agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{agent_id: int, agent_name: string, active_tickets: int, max_capacity: int, load_percent: float}>
     */
    public function getAgentLoad(?int $agentId = null, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();

        $workspaceClause = '';
        $params = [];
        if ($phoneNumberId !== null) {
            $workspaceClause = ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }

        $agentClause = '';
        if ($agentId !== null) {
            $agentClause = ' AND a.id = :agentId';
            $params['agentId'] = $agentId;
        }

        $sql = "SELECT
            a.id as agent_id,
            a.name as agent_name,
            a.max_active_tickets as max_capacity,
            (SELECT COUNT(*) FROM tickets t
             WHERE t.assigned_agent_id = a.id
             AND t.status IN ('new', 'active', 'waiting'){$workspaceClause}) as active_tickets
        FROM agents a
        WHERE a.active = 1{$agentClause}
        ORDER BY a.id ASC";

        $results = $conn->fetchAllAssociative($sql, $params);

        $agentMetrics = [];
        foreach ($results as $row) {
            $maxCapacity = (int) $row['max_capacity'];
            $activeTickets = (int) $row['active_tickets'];
            $agentMetrics[] = [
                'agent_id' => (int) $row['agent_id'],
                'agent_name' => $row['agent_name'],
                'active_tickets' => $activeTickets,
                'max_capacity' => $maxCapacity,
                'load_percent' => $maxCapacity > 0 ? round($activeTickets / $maxCapacity * 100, 1) : 0.0,
            ];
        }

        return $agentMetrics;
    }

    /**
     * Get tickets where assigned_agent_id IS NULL.
     *
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param \DateTimeImmutable|null $from Optional start of date range
     * @param \DateTimeImmutable|null $to Optional end of date range
     * @param int $limit Maximum results (default 50)
     * @return array{count: int, tickets: array<int, array{id: int, contact_name: string|null, created_at: string, priority: string}>}
     */
    public function getUnassigned(?string $phoneNumberId = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 50): array
    {
        $conn = $this->entityManager->getConnection();

        $sql = "SELECT
            t.id,
            c.name as contact_name,
            t.created_at,
            t.priority
        FROM tickets t
        LEFT JOIN contacts c ON c.id = t.contact_id
        WHERE t.assigned_agent_id IS NULL";

        $params = [];

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }

        if ($from !== null) {
            $sql .= ' AND t.created_at >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s');
        }

        if ($to !== null) {
            $sql .= ' AND t.created_at < :to';
            $params['to'] = $to->format('Y-m-d H:i:s');
        }

        // Count first
        $countSql = str_replace(
            'SELECT t.id, c.name as contact_name, t.created_at, t.priority',
            'SELECT COUNT(*) as cnt',
            $sql
        );
        $countResult = $conn->fetchAssociative($countSql, $params);
        $count = (int) ($countResult['cnt'] ?? 0);

        $sql .= ' ORDER BY t.created_at DESC LIMIT ' . $limit;
        $results = $conn->fetchAllAssociative($sql, $params);

        $tickets = [];
        foreach ($results as $row) {
            $tickets[] = [
                'id' => (int) $row['id'],
                'contact_name' => $row['contact_name'],
                'created_at' => $row['created_at'],
                'priority' => $row['priority'],
            ];
        }

        return [
            'count' => $count,
            'tickets' => $tickets,
        ];
    }

    /**
     * Get FRT aggregated by day within a date range.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{date: string, avg_frt_seconds: float, ticket_count: int}>
     */
    public function getFrtTrend(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();

        $timeDiff = $this->getTimeDiffExpression('t.created_at', 't.first_response_at');

        $sql = "SELECT
            DATE(t.created_at) as date,
            AVG({$timeDiff}) as avg_frt,
            COUNT(t.id) as ticket_count
        FROM tickets t
        WHERE t.first_response_at IS NOT NULL
        AND t.created_at >= :from AND t.created_at < :to";

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }

        $sql .= ' GROUP BY DATE(t.created_at) ORDER BY date ASC';

        $results = $conn->fetchAllAssociative($sql, $params);

        $trend = [];
        foreach ($results as $row) {
            $trend[] = [
                'date' => $row['date'],
                'avg_frt_seconds' => $row['avg_frt'] !== null ? round((float) $row['avg_frt'], 1) : 0.0,
                'ticket_count' => (int) $row['ticket_count'],
            ];
        }

        return $trend;
    }

    /**
     * Get ticket volume grouped by day-of-week x hour for heatmap.
     *
     * dayOfWeek is normalized to ISO: 1=Monday, 7=Sunday.
     * MySQL DAYOFWEEK: 1=Sunday, 7=Saturday → convert via (dow+5)%7+1
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{day_of_week: int, hour: int, count: int}>
     */
    public function getHeatmap(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();

        if ($this->isPostgres) {
            // PostgreSQL: EXTRACT(DOW) → 0=Sunday, 6=Saturday
            // Normalize to ISO: (dow+6)%7+1 → 1=Monday, 7=Sunday
            $dowExpr = "((EXTRACT(DOW FROM t.created_at) + 6) % 7 + 1)";
            $hourExpr = "EXTRACT(HOUR FROM t.created_at)";
        } else {
            // MySQL: DAYOFWEEK → 1=Sunday, 7=Saturday
            // Normalize to ISO: (dow+5)%7+1 → 1=Monday, 7=Sunday
            $dowExpr = "((DAYOFWEEK(t.created_at) + 5) % 7 + 1)";
            $hourExpr = "HOUR(t.created_at)";
        }

        $sql = "SELECT
            {$dowExpr} as day_of_week,
            {$hourExpr} as hour,
            COUNT(t.id) as count
        FROM tickets t
        WHERE t.created_at >= :from AND t.created_at < :to";

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }

        $sql .= ' GROUP BY day_of_week, hour ORDER BY day_of_week ASC, hour ASC';

        $results = $conn->fetchAllAssociative($sql, $params);

        $heatmap = [];
        foreach ($results as $row) {
            $count = (int) $row['count'];
            if ($count > 0) {
                $heatmap[] = [
                    'day_of_week' => (int) $row['day_of_week'],
                    'hour' => (int) $row['hour'],
                    'count' => $count,
                ];
            }
        }

        return $heatmap;
    }

    /**
     * Get contacts with >= threshold tickets in the last N days.
     *
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int $threshold Minimum ticket count (default 3)
     * @param int $days Lookback window in days (default 90)
     * @return array<int, array{contact_id: int, contact_name: string|null, email: string|null, ticket_count: int, last_ticket_at: string}>
     */
    public function getRecurringCustomers(?string $phoneNumberId = null, int $threshold = 3, int $days = 90): array
    {
        $conn = $this->entityManager->getConnection();

        $since = (new \DateTimeImmutable())->modify("-{$days} days");

        $sql = "SELECT
            c.id as contact_id,
            c.name as contact_name,
            c.email as email,
            COUNT(t.id) as ticket_count,
            MAX(t.created_at) as last_ticket_at
        FROM contacts c
        INNER JOIN tickets t ON t.contact_id = c.id
        WHERE t.created_at >= :since";

        $params = ['since' => $since->format('Y-m-d H:i:s')];

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }

        $sql .= ' GROUP BY c.id, c.name, c.email
                  HAVING COUNT(t.id) >= :threshold
                  ORDER BY ticket_count DESC';

        $params['threshold'] = $threshold;

        $results = $conn->fetchAllAssociative($sql, $params);

        $recurring = [];
        foreach ($results as $row) {
            $recurring[] = [
                'contact_id' => (int) $row['contact_id'],
                'contact_name' => $row['contact_name'],
                'email' => $row['email'],
                'ticket_count' => (int) $row['ticket_count'],
                'last_ticket_at' => $row['last_ticket_at'],
            ];
        }

        return $recurring;
    }

    /**
     * Get tickets that transitioned out of resolved status (auto_reopened events).
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int $limit Maximum results (default 100)
     * @return array<int, array{ticket_id: int, contact_name: string|null, resolved_at: string|null, reopened_at: string, current_status: string}>
     */
    public function getReopened(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null, int $limit = 100): array
    {
        $conn = $this->entityManager->getConnection();

        $sql = "SELECT
            t.id as ticket_id,
            c.name as contact_name,
            t.resolved_at,
            te.created_at as reopened_at,
            t.status as current_status
        FROM ticket_events te
        INNER JOIN tickets t ON t.id = te.ticket_id
        LEFT JOIN contacts c ON c.id = t.contact_id
        WHERE te.type = 'auto_reopened'
        AND te.created_at >= :from AND te.created_at < :to";

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phoneNumberId';
            $params['phoneNumberId'] = $phoneNumberId;
        }

        $sql .= ' ORDER BY te.created_at DESC LIMIT ' . $limit;

        $results = $conn->fetchAllAssociative($sql, $params);

        $reopened = [];
        foreach ($results as $row) {
            $reopened[] = [
                'ticket_id' => (int) $row['ticket_id'],
                'contact_name' => $row['contact_name'],
                'resolved_at' => $row['resolved_at'],
                'reopened_at' => $row['reopened_at'],
                'current_status' => $row['current_status'],
            ];
        }

        return $reopened;
    }
}
