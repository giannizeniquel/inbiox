<?php

namespace App\Reporte\Repository;

use App\Ticket\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
     * Build the optional ticket filters shared by the native report queries
     * and bind their parameters. The clauses keep a fixed order: agent,
     * workspace, team. Returns an empty string when no filter is set.
     *
     * @param array<string, mixed> $params Query parameters, extended in place
     * @param string $alias Alias of the tickets table in the query
     */
    private function ticketFilterClause(array &$params, string $alias, ?int $agentId, ?string $phoneNumberId, ?int $teamId): string
    {
        $clause = '';

        if ($agentId !== null) {
            $clause .= " AND {$alias}.assigned_agent_id = :agentId";
            $params['agentId'] = $agentId;
        }

        if ($phoneNumberId !== null) {
            $clause .= " AND {$alias}.phone_number_id = :phoneNumberId";
            $params['phoneNumberId'] = $phoneNumberId;
        }

        if ($teamId !== null) {
            $clause .= " AND {$alias}.team_id = :teamId";
            $params['teamId'] = $teamId;
        }

        return $clause;
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
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array{avg_frt_seconds: float|null, min_frt_seconds: int|null, max_frt_seconds: int|null, avg_frt_minutes: float|null, min_frt_minutes: int|null, max_frt_minutes: int|null}
     */
    public function getFrtMetrics(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
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
        
        $sql .= $this->ticketFilterClause($params, 't', $agentId, $phoneNumberId, $teamId);

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
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array{avg_rt_seconds: float|null, min_rt_seconds: int|null, max_rt_seconds: int|null, avg_rt_minutes: float|null, min_rt_minutes: int|null, max_rt_minutes: int|null}
     */
    public function getRtMetrics(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
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
        
        $sql .= $this->ticketFilterClause($params, 't', $agentId, $phoneNumberId, $teamId);

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
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array{new: int, active: int, waiting: int, resolved: int, closed: int}
     */
    public function getCountsByStatus(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
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

        if ($teamId !== null) {
            $qb->andWhere('t.teamId = :teamId')
               ->setParameter('teamId', $teamId);
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
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array<int, array{date: string, tickets_created: int, tickets_resolved: int}>
     */
    public function getVolumeOverTime(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $conn = $this->entityManager->getConnection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $filterClause = $this->ticketFilterClause($params, 't', $agentId, $phoneNumberId, $teamId);

        // Created tickets grouped by date
        $sqlCreated = 'SELECT DATE(t.created_at) as date, COUNT(t.id) as tickets_created
        FROM tickets t
        WHERE t.created_at >= :from AND t.created_at < :to' . $filterClause . '
        GROUP BY DATE(t.created_at)';

        $createdResults = $conn->fetchAllAssociative($sqlCreated, $params);

        // Resolved tickets grouped by date
        $sqlResolved = 'SELECT DATE(t.resolved_at) as date, COUNT(t.id) as tickets_resolved
        FROM tickets t
        WHERE t.resolved_at IS NOT NULL AND t.resolved_at >= :from AND t.resolved_at < :to' . $filterClause . '
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
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array<int, array{agent_id: int, agent_name: string|null, tickets_assigned: int, tickets_resolved: int, current_active_tickets: int, max_active_tickets: int, current_load: float, avg_frt_seconds: float|null, avg_frt_minutes: float|null, avg_rt_seconds: float|null, avg_rt_minutes: float|null}>
     */
    public function getAgentPerformance(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $conn = $this->entityManager->getConnection();

        $frtTimeDiff = $this->getTimeDiffExpression('t.created_at', 't.first_response_at');
        $rtTimeDiff = $this->getTimeDiffExpression('t.created_at', 't.resolved_at');

        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        $subFilterClause = $this->ticketFilterClause($params, 't2', null, $phoneNumberId, $teamId);
        $joinFilterClause = $this->ticketFilterClause($params, 't', null, $phoneNumberId, $teamId);

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
             AND t2.status IN ('new', 'active', 'waiting'){$subFilterClause}) as current_active_tickets
        FROM agents a
        LEFT JOIN tickets t ON t.assigned_agent_id = a.id
            AND t.created_at >= :from AND t.created_at < :to{$joinFilterClause}
        WHERE a.active = 1";

        if ($agentId !== null) {
            $sql .= ' AND a.id = :agentId';
            $params['agentId'] = $agentId;
        }

        $sql .= " GROUP BY a.id, a.name, a.max_active_tickets ORDER BY a.id ASC";
        
        $results = $conn->fetchAllAssociative($sql, $params);
        
        $agentMetrics = [];
        foreach ($results as $row) {
            $agentId = (int) $row['agent_id'];
            $avgFrt = $row['avg_frt'] !== null ? (float) $row['avg_frt'] : null;
            $avgRt = $row['avg_rt'] !== null ? (float) $row['avg_rt'] : null;
            $maxActive = (int) ($row['max_active_tickets'] ?? 10);
            $currentActive = (int) ($row['current_active_tickets'] ?? 0);
            
            $agentMetrics[] = [
                'agent_id' => $agentId,
                'agent_name' => $row['agent_name'],
                'tickets_assigned' => (int) $row['tickets_assigned'],
                'tickets_resolved' => (int) $row['tickets_resolved'],
                'current_active_tickets' => $currentActive,
                'max_active_tickets' => $maxActive,
                'current_load' => $maxActive > 0 ? round($currentActive / $maxActive, 2) : 0.0,
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

        $params = [];
        $workspaceClause = $this->ticketFilterClause($params, 't', null, $phoneNumberId, null);

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

        $sql .= $this->ticketFilterClause($params, 't', null, $phoneNumberId, null);

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

        $sql .= $this->ticketFilterClause($params, 't', null, $phoneNumberId, null);

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

        $sql .= $this->ticketFilterClause($params, 't', null, $phoneNumberId, null);

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

        $sql .= $this->ticketFilterClause($params, 't', null, $phoneNumberId, null);

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

        $sql .= $this->ticketFilterClause($params, 't', null, $phoneNumberId, null);

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

    /**
     * Get the attribution cutoff — the earliest `created_at` among any
     * `sender_type IN ('agent', 'automatic', 'contact')` message.
     *
     * Messages before this timestamp have `sender_type IS NULL` (legacy,
     * pre-attribution-rollout rows) and must be excluded from response-delay
     * sampling and attribution everywhere. Returns null only when the whole
     * `messages` table is unattributed (no non-NULL sender_type row exists).
     */
    public function getAttributionCutoff(): ?\DateTimeImmutable
    {
        $conn = $this->entityManager->getConnection();

        $sql = "SELECT MIN(c) AS data_available_from FROM (
                (SELECT created_at AS c FROM messages WHERE sender_type = 'agent'     ORDER BY created_at LIMIT 1)
              UNION ALL
                (SELECT created_at AS c FROM messages WHERE sender_type = 'automatic' ORDER BY created_at LIMIT 1)
              UNION ALL
                (SELECT created_at AS c FROM messages WHERE sender_type = 'contact'   ORDER BY created_at LIMIT 1)
            ) probe";

        $result = $conn->fetchAssociative($sql);
        $value = $result['data_available_from'] ?? null;

        return $value !== null ? new \DateTimeImmutable($value) : null;
    }

    /**
     * Get aggregate + per-agent response-delay metrics.
     *
     * Episode-partition scoping: a message belongs to the ticket most recently
     * opened at or before it on the same conversation (`[ticket.created_at,
     * next_ticket.created_at)`). Burst collapse: consecutive inbound messages
     * with no intervening `sender_type='agent'` reply are one run — exactly
     * one delay sample per run, measured from the run's first message to the
     * next agent reply. `automatic` messages never split a burst nor stop the
     * clock. Legacy `sender_type IS NULL` rows (before the attribution
     * cutoff) are excluded from the whole stream. Range filter is on
     * `tickets.created_at` (episode start), not reply timestamp.
     *
     * @return array{aggregate: array{avg_delay_seconds: float|null, sample_count: int, unanswered_inbound_count: int}, per_agent: array<int, array{agent_id: int, agent_name: string|null, avg_delay_seconds: float|null, sample_count: int}>}
     */
    public function getResponseDelayMetrics(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $rows = $this->fetchResponseDelayRows($from, $to, $phoneNumberId);

        return $this->aggregateResponseDelayRows($rows);
    }

    /**
     * One row per replying agent (and one with a null agent for the bursts
     * nobody answered), with its sample count, delay sum and unanswered count.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchResponseDelayRows(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId): array
    {
        $conn = $this->entityManager->getConnection();
        $timeDiff = $this->getTimeDiffExpression('b.inbound_at', 'r.reply_at');

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        $workspaceClause = $this->ticketFilterClause($params, 't', null, $phoneNumberId, null);

        $sql = "WITH cutoff AS (
                SELECT MIN(c) AS data_available_from FROM (
                    (SELECT created_at AS c FROM messages WHERE sender_type = 'agent'     ORDER BY created_at LIMIT 1)
                  UNION ALL
                    (SELECT created_at AS c FROM messages WHERE sender_type = 'automatic' ORDER BY created_at LIMIT 1)
                  UNION ALL
                    (SELECT created_at AS c FROM messages WHERE sender_type = 'contact'   ORDER BY created_at LIMIT 1)
                ) probe
            ),
            scoped_convs AS (
                SELECT DISTINCT t.conversation_id FROM tickets t
                WHERE t.conversation_id IS NOT NULL
                  AND t.created_at >= :from AND t.created_at < :to{$workspaceClause}
            ),
            episodes AS (
                SELECT t.id AS ticket_id, t.conversation_id, t.created_at AS episode_start,
                       LEAD(t.created_at) OVER (PARTITION BY t.conversation_id
                                                ORDER BY t.created_at, t.id) AS episode_end
                FROM tickets t
                JOIN scoped_convs sc ON sc.conversation_id = t.conversation_id
            ),
            msgs AS (
                SELECT e.ticket_id, m.id, m.created_at, m.direction, m.sender_type, m.sender_agent_id
                FROM episodes e
                JOIN messages m ON m.conversation_id = e.conversation_id
                               AND m.created_at >= e.episode_start
                               AND (e.episode_end IS NULL OR m.created_at < e.episode_end)
                CROSS JOIN cutoff cf
                WHERE e.episode_start >= :from AND e.episode_start < :to
                  AND m.sender_type IS NOT NULL
                  AND m.created_at >= cf.data_available_from
            ),
            seq AS (
                SELECT s.*,
                       SUM(CASE WHEN s.sender_type = 'agent' THEN 1 ELSE 0 END)
                         OVER (PARTITION BY s.ticket_id ORDER BY s.created_at, s.id
                               ROWS UNBOUNDED PRECEDING) AS reply_seq
                FROM msgs s
            ),
            bursts AS (
                SELECT ticket_id, reply_seq, created_at AS inbound_at, burst_size
                FROM (
                    SELECT q.ticket_id, q.reply_seq, q.created_at, q.id,
                           ROW_NUMBER() OVER (PARTITION BY q.ticket_id, q.reply_seq
                                              ORDER BY q.created_at, q.id) AS rn,
                           COUNT(*) OVER (PARTITION BY q.ticket_id, q.reply_seq) AS burst_size
                    FROM seq q WHERE q.direction = 'inbound'
                ) ranked
                WHERE rn = 1
            ),
            agent_replies AS (
                SELECT ticket_id, reply_seq, created_at AS reply_at, sender_agent_id
                FROM seq WHERE sender_type = 'agent'
            )
            SELECT r.sender_agent_id AS agent_id, a.name AS agent_name,
                   COUNT(r.reply_at) AS sample_count,
                   SUM({$timeDiff}) AS sum_delay,
                   SUM(CASE WHEN r.reply_at IS NULL THEN 1 ELSE 0 END) AS unanswered_count
            FROM bursts b
            LEFT JOIN agent_replies r ON r.ticket_id = b.ticket_id
                                     AND r.reply_seq = b.reply_seq + 1
            LEFT JOIN agents a ON a.id = r.sender_agent_id
            GROUP BY r.sender_agent_id, a.name
            ORDER BY r.sender_agent_id";

        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * Fold the per-agent rows into the aggregate and the per-agent list.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{aggregate: array{avg_delay_seconds: float|null, sample_count: int, unanswered_inbound_count: int}, per_agent: array<int, array{agent_id: int, agent_name: string|null, avg_delay_seconds: float|null, sample_count: int}>}
     */
    private function aggregateResponseDelayRows(array $rows): array
    {
        $aggregateSampleCount = 0;
        $aggregateSumDelay = 0.0;
        $unansweredInboundCount = 0;
        $perAgent = [];

        foreach ($rows as $row) {
            $sampleCount = (int) $row['sample_count'];
            $sumDelay = $row['sum_delay'] !== null ? (float) $row['sum_delay'] : null;
            $unansweredInboundCount += (int) $row['unanswered_count'];

            if ($sampleCount > 0) {
                $aggregateSampleCount += $sampleCount;
                $aggregateSumDelay += $sumDelay ?? 0.0;
            }

            if ($row['agent_id'] !== null) {
                $perAgent[] = [
                    'agent_id' => (int) $row['agent_id'],
                    'agent_name' => $row['agent_name'],
                    'avg_delay_seconds' => $sampleCount > 0 ? round($sumDelay / $sampleCount, 2) : null,
                    'sample_count' => $sampleCount,
                ];
            }
        }

        return [
            'aggregate' => [
                'avg_delay_seconds' => $aggregateSampleCount > 0 ? round($aggregateSumDelay / $aggregateSampleCount, 2) : null,
                'sample_count' => $aggregateSampleCount,
                'unanswered_inbound_count' => $unansweredInboundCount,
            ],
            'per_agent' => $perAgent,
        ];
    }

    /**
     * Get per-ticket participation drill-down: participants, per-agent
     * delay samples, automatic activity, and burst pairs — scoped to the
     * ticket's own episode. Reuses the same burst-based sampling and
     * clock start/stop rules as {@see getResponseDelayMetrics()}.
     *
     * Native SQL because the burst pairing needs CTEs and window functions
     * (`SUM() OVER`, `ROW_NUMBER() OVER`, `COUNT() OVER`), which neither DQL
     * nor DBAL's QueryBuilder can express. Parameters are bound. Tested
     * directly by the `testGetTicketParticipation*` and degenerate-tie cases
     * in `tests/Integration/ReportingResponseDelayTest.php`.
     *
     * @return array{ticket_id: int, data_available_from: \DateTimeImmutable|null, partial_data: bool, participants: array<int, array{agent_id: int, agent_name: string|null, message_count: int, sample_count: int, avg_delay_seconds: float|null}>, automatic_message_count: int, unanswered_inbound_count: int, pairs: array<int, array{run_start_at: string, reply_at: string|null, agent_id: int|null, delay_seconds: float|null}>}|null null when the ticket does not exist or has no conversation
     */
    public function getTicketParticipation(int $ticketId): ?array
    {
        $episode = $this->findParticipationEpisode($ticketId);
        if ($episode === null) {
            return null;
        }

        $cutoff = $this->getAttributionCutoff();
        $pairRows = $this->fetchParticipationPairRows($episode, $cutoff);
        $messageCounts = $this->countEpisodeMessagesBySender($episode);
        [$pairs, $participantStats, $unansweredInboundCount] = $this->foldParticipationPairs($pairRows);
        $participants = $this->buildParticipants($messageCounts['by_agent'], $participantStats);

        return [
            'ticket_id' => $ticketId,
            'data_available_from' => $cutoff,
            'partial_data' => $messageCounts['unattributed'] > 0 || $cutoff === null,
            'participants' => $participants,
            'automatic_message_count' => $messageCounts['automatic'],
            'unanswered_inbound_count' => $unansweredInboundCount,
            'pairs' => $pairs,
        ];
    }

    /**
     * The ticket's episode: its conversation, its own creation time as the
     * start, and the next ticket's creation time on that conversation as the
     * end (null for the latest ticket).
     *
     * @return array{conversation_id: int, start: string, end: string|null}|null null when the ticket does not exist or has no conversation
     */
    private function findParticipationEpisode(int $ticketId): ?array
    {
        $conn = $this->entityManager->getConnection();

        $ticketRow = $conn->fetchAssociative(
            'SELECT id, conversation_id, created_at FROM tickets WHERE id = :ticketId',
            ['ticketId' => $ticketId]
        );

        if ($ticketRow === false || $ticketRow['conversation_id'] === null) {
            return null;
        }

        $conversationId = (int) $ticketRow['conversation_id'];
        $episodeStart = $ticketRow['created_at'];

        // Episode end: next ticket on the same conversation, (created_at, id)
        // lexicographic tie-break — same order as the LEAD() version, so both
        // queries agree on episode bounds (see design.md "degenerate tie" note).
        $episodeEndRow = $conn->fetchAssociative(
            'SELECT MIN(t2.created_at) AS episode_end FROM tickets t2
             WHERE t2.conversation_id = :conversationId
               AND (t2.created_at > :episodeStart
                    OR (t2.created_at = :episodeStart AND t2.id > :ticketId))',
            ['conversationId' => $conversationId, 'episodeStart' => $episodeStart, 'ticketId' => $ticketId]
        );

        return [
            'conversation_id' => $conversationId,
            'start' => $episodeStart,
            'end' => $episodeEndRow['episode_end'] ?? null,
        ];
    }

    /**
     * One row per inbound burst of the episode (attributed messages after
     * the cutoff only), joined to the agent reply that closed it, if any.
     *
     * @param array{conversation_id: int, start: string, end: string|null} $episode
     * @return list<array<string, mixed>>
     */
    private function fetchParticipationPairRows(array $episode, ?\DateTimeImmutable $cutoff): array
    {
        $conn = $this->entityManager->getConnection();
        $timeDiff = $this->getTimeDiffExpression('b.inbound_at', 'r.reply_at');

        $params = [
            'conversationId' => $episode['conversation_id'],
            'episodeStart' => $episode['start'],
            'cutoff' => $cutoff !== null ? $cutoff->format('Y-m-d H:i:s') : '1970-01-01 00:00:00',
        ];
        $episodeEndClause = '';
        if ($episode['end'] !== null) {
            $episodeEndClause = ' AND m.created_at < :episodeEnd';
            $params['episodeEnd'] = $episode['end'];
        }

        $sql = "WITH msgs AS (
                SELECT m.id, m.created_at, m.direction, m.sender_type, m.sender_agent_id
                FROM messages m
                WHERE m.conversation_id = :conversationId
                  AND m.created_at >= :episodeStart{$episodeEndClause}
                  AND m.sender_type IS NOT NULL
                  AND m.created_at >= :cutoff
            ),
            seq AS (
                SELECT s.*,
                       SUM(CASE WHEN s.sender_type = 'agent' THEN 1 ELSE 0 END)
                         OVER (ORDER BY s.created_at, s.id ROWS UNBOUNDED PRECEDING) AS reply_seq
                FROM msgs s
            ),
            bursts AS (
                SELECT reply_seq, created_at AS inbound_at, burst_size
                FROM (
                    SELECT q.reply_seq, q.created_at, q.id,
                           ROW_NUMBER() OVER (PARTITION BY q.reply_seq ORDER BY q.created_at, q.id) AS rn,
                           COUNT(*) OVER (PARTITION BY q.reply_seq) AS burst_size
                    FROM seq q WHERE q.direction = 'inbound'
                ) ranked
                WHERE rn = 1
            ),
            agent_replies AS (
                SELECT reply_seq, created_at AS reply_at, sender_agent_id
                FROM seq WHERE sender_type = 'agent'
            )
            SELECT b.inbound_at, b.burst_size, r.reply_at, r.sender_agent_id AS agent_id, a.name AS agent_name,
                   {$timeDiff} AS delay_seconds
            FROM bursts b
            LEFT JOIN agent_replies r ON r.reply_seq = b.reply_seq + 1
            LEFT JOIN agents a ON a.id = r.sender_agent_id
            ORDER BY b.inbound_at";

        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * Rollup over ALL episode messages (including legacy sender_type IS
     * NULL rows) for message counts / automatic activity / unattributed
     * detection — independent of the cutoff-filtered seq/bursts pipeline.
     *
     * @param array{conversation_id: int, start: string, end: string|null} $episode
     * @return array{by_agent: array<int, int>, automatic: int, unattributed: int}
     */
    private function countEpisodeMessagesBySender(array $episode): array
    {
        $conn = $this->entityManager->getConnection();

        $rollupParams = ['conversationId' => $episode['conversation_id'], 'episodeStart' => $episode['start']];
        $rollupEpisodeEndClause = '';
        if ($episode['end'] !== null) {
            $rollupEpisodeEndClause = ' AND m.created_at < :episodeEnd';
            $rollupParams['episodeEnd'] = $episode['end'];
        }
        $rollupSql = "SELECT m.sender_type, m.sender_agent_id, COUNT(*) AS message_count
            FROM messages m
            WHERE m.conversation_id = :conversationId
              AND m.created_at >= :episodeStart{$rollupEpisodeEndClause}
            GROUP BY m.sender_type, m.sender_agent_id";
        $rollupRows = $conn->fetchAllAssociative($rollupSql, $rollupParams);

        $messageCountByAgent = [];
        $automaticMessageCount = 0;
        $unattributedCount = 0;
        foreach ($rollupRows as $row) {
            $count = (int) $row['message_count'];
            if ($row['sender_type'] === null) {
                $unattributedCount += $count;
            } elseif ($row['sender_type'] === 'automatic') {
                $automaticMessageCount += $count;
            } elseif ($row['sender_type'] === 'agent' && $row['sender_agent_id'] !== null) {
                $agentId = (int) $row['sender_agent_id'];
                $messageCountByAgent[$agentId] = ($messageCountByAgent[$agentId] ?? 0) + $count;
            }
        }

        return [
            'by_agent' => $messageCountByAgent,
            'automatic' => $automaticMessageCount,
            'unattributed' => $unattributedCount,
        ];
    }

    /**
     * Turn the burst rows into the public pairs, per-agent delay totals and
     * the number of bursts nobody answered.
     *
     * @param list<array<string, mixed>> $pairRows
     * @return array{0: list<array{run_start_at: string, reply_at: string|null, agent_id: int|null, delay_seconds: float|null}>, 1: array<int, array{agent_name: string|null, sample_count: int, sum_delay: float}>, 2: int}
     */
    private function foldParticipationPairs(array $pairRows): array
    {
        $participantStats = [];
        $unansweredInboundCount = 0;
        $pairs = [];

        foreach ($pairRows as $row) {
            $agentId = $row['agent_id'] !== null ? (int) $row['agent_id'] : null;
            $delaySeconds = $row['delay_seconds'] !== null ? (float) $row['delay_seconds'] : null;

            $pairs[] = [
                'run_start_at' => $row['inbound_at'],
                'reply_at' => $row['reply_at'],
                'agent_id' => $agentId,
                'delay_seconds' => $delaySeconds,
            ];

            if ($agentId === null) {
                $unansweredInboundCount++;
                continue;
            }

            if (!isset($participantStats[$agentId])) {
                $participantStats[$agentId] = [
                    'agent_name' => $row['agent_name'],
                    'sample_count' => 0,
                    'sum_delay' => 0.0,
                ];
            }
            $participantStats[$agentId]['sample_count']++;
            $participantStats[$agentId]['sum_delay'] += $delaySeconds ?? 0.0;
        }

        return [$pairs, $participantStats, $unansweredInboundCount];
    }

    /**
     * One entry per agent who wrote in the episode or answered a burst,
     * sorted by agent id.
     *
     * @param array<int, int> $messageCountByAgent
     * @param array<int, array{agent_name: string|null, sample_count: int, sum_delay: float}> $participantStats
     * @return list<array{agent_id: int, agent_name: string|null, message_count: int, sample_count: int, avg_delay_seconds: float|null}>
     */
    private function buildParticipants(array $messageCountByAgent, array $participantStats): array
    {
        $conn = $this->entityManager->getConnection();

        // Fallback agent-name lookup: an agent who replied without a
        // preceding unanswered burst (unprompted message) has message_count
        // but never appears in $participantStats.
        $missingAgentIds = array_diff(array_keys($messageCountByAgent), array_keys($participantStats));
        $agentNameMap = [];
        if (!empty($missingAgentIds)) {
            $nameRows = $conn->fetchAllAssociative(
                'SELECT id, name FROM agents WHERE id IN (?)',
                [array_values(array_map('intval', $missingAgentIds))],
                [ArrayParameterType::INTEGER]
            );
            foreach ($nameRows as $nameRow) {
                $agentNameMap[(int) $nameRow['id']] = $nameRow['name'];
            }
        }

        $agentIds = array_unique(array_merge(array_keys($messageCountByAgent), array_keys($participantStats)));
        sort($agentIds);
        $participants = [];
        foreach ($agentIds as $agentId) {
            $stats = $participantStats[$agentId] ?? ['agent_name' => null, 'sample_count' => 0, 'sum_delay' => 0.0];
            $participants[] = [
                'agent_id' => $agentId,
                'agent_name' => $stats['agent_name'] ?? $agentNameMap[$agentId] ?? null,
                'message_count' => $messageCountByAgent[$agentId] ?? 0,
                'sample_count' => $stats['sample_count'],
                'avg_delay_seconds' => $stats['sample_count'] > 0 ? round($stats['sum_delay'] / $stats['sample_count'], 2) : null,
            ];
        }

        return $participants;
    }

    /**
     * Empty the tables that `app:seed-reports` refills: ticket_events,
     * tickets, agent_shifts, contacts and agents, in that order, with foreign
     * key checks off while it runs. Test database only; the command guards it.
     *
     * Native SQL because DQL and the QueryBuilder have no TRUNCATE and no
     * session variables (`SET FOREIGN_KEY_CHECKS`). TRUNCATE commits
     * implicitly in MySQL, so never call this inside a transaction.
     */
    public function truncateSeedTables(): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE ticket_events');
        $conn->executeStatement('TRUNCATE TABLE tickets');
        $conn->executeStatement('TRUNCATE TABLE agent_shifts');
        $conn->executeStatement('TRUNCATE TABLE contacts');
        $conn->executeStatement('TRUNCATE TABLE agents');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
