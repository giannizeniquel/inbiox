<?php

namespace App\Repository;

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
 */
class ReportingRepository extends ServiceEntityRepository
{
    private bool $isPostgres = false;
    
    public function __construct(
        ManagerRegistry $registry,
        private EntityManagerInterface $entityManager
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
     * @return array{avg_frt_seconds: float|null, min_frt_seconds: int|null, max_frt_seconds: int|null, avg_frt_minutes: float|null, min_frt_minutes: int|null, max_frt_minutes: int|null}
     */
    public function getFrtMetrics(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null): array
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
     * @return array{avg_rt_seconds: float|null, min_rt_seconds: int|null, max_rt_seconds: int|null, avg_rt_minutes: float|null, min_rt_minutes: int|null, max_rt_minutes: int|null}
     */
    public function getRtMetrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array
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
     * @return array{new: int, active: int, waiting: int, resolved: int, closed: int}
     */
    public function getCountsByStatus(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('t');

        $qb->select('t.status', 'COUNT(t.id) as count')
            ->andWhere('t.createdAt >= :from AND t.createdAt < :to')
            ->groupBy('t.status')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

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
     * @return array<int, array{date: string, tickets_created: int, tickets_resolved: int}>
     */
    public function getVolumeOverTime(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->entityManager->getConnection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        
        // Created tickets grouped by date
        $sqlCreated = 'SELECT DATE(t.created_at) as date, COUNT(t.id) as tickets_created
        FROM tickets t
        WHERE t.created_at >= :from AND t.created_at < :to
        GROUP BY DATE(t.created_at)';
        
        $createdResults = $conn->fetchAllAssociative($sqlCreated, $params);

        // Resolved tickets grouped by date
        $sqlResolved = 'SELECT DATE(t.resolved_at) as date, COUNT(t.id) as tickets_resolved
        FROM tickets t
        WHERE t.resolved_at IS NOT NULL AND t.resolved_at >= :from AND t.resolved_at < :to
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
     * @return array<int, array{agent_id: int, tickets_assigned: int, tickets_resolved: int, avg_frt_seconds: float|null, avg_frt_minutes: float|null, avg_rt_seconds: float|null, avg_rt_minutes: float|null}>
     */
    public function getAgentPerformance(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null): array
    {
        $conn = $this->entityManager->getConnection();
        
        $frtTimeDiff = $this->getTimeDiffExpression('t.created_at', 't.first_response_at');
        $rtTimeDiff = $this->getTimeDiffExpression('t.created_at', 't.resolved_at');
        
        $sql = "SELECT 
            t.assigned_agent_id as agent_id,
            COUNT(t.id) as tickets_assigned,
            SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as tickets_resolved,
            AVG(CASE WHEN t.first_response_at IS NOT NULL THEN {$frtTimeDiff} ELSE NULL END) as avg_frt,
            AVG(CASE WHEN t.resolved_at IS NOT NULL THEN {$rtTimeDiff} ELSE NULL END) as avg_rt
        FROM tickets t
        WHERE t.assigned_agent_id IS NOT NULL
        AND t.created_at >= :from AND t.created_at < :to
        GROUP BY t.assigned_agent_id";
        
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        
        if ($agentId !== null) {
            $sql .= ' AND t.assigned_agent_id = :agentId';
            $params['agentId'] = $agentId;
        }
        
        $results = $conn->fetchAllAssociative($sql, $params);
        
        $agentMetrics = [];
        foreach ($results as $row) {
            $avgFrt = $row['avg_frt'] !== null ? (float) $row['avg_frt'] : null;
            $avgRt = $row['avg_rt'] !== null ? (float) $row['avg_rt'] : null;
            
            $agentMetrics[] = [
                'agent_id' => (int) $row['agent_id'],
                'tickets_assigned' => (int) $row['tickets_assigned'],
                'tickets_resolved' => (int) $row['tickets_resolved'],
                'avg_frt_seconds' => $avgFrt,
                'avg_frt_minutes' => $avgFrt !== null ? round($avgFrt / 60, 2) : null,
                'avg_rt_seconds' => $avgRt,
                'avg_rt_minutes' => $avgRt !== null ? round($avgRt / 60, 2) : null,
            ];
        }
        
        return $agentMetrics;
    }
}