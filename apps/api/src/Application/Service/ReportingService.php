<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Repository\ReportingRepository;

/**
 * Service for reporting and metrics queries.
 *
 * Orchestrates reporting data retrieval and formats responses.
 * All dates must be in UTC.
 *
 * Ownership scoping:
 * - All methods accept an optional ?int $agentId parameter for query-level filtering
 * - When set, all results are scoped to the given agent
 * - The controller (ReportingController) decides the scope based on user role
 */
class ReportingService
{
    public function __construct(
        private ReportingRepository $reportingRepository
    ) {}

    /**
     * Get First Response Time metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @return array{avg_frt: float|null, min_frt: int|null, max_frt: int|null}
     */
    public function getFrt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getFrtMetrics($from, $to, $agentId);
    }

    /**
     * Get Resolution Time metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @return array{avg_rt: float|null, min_rt: int|null, max_rt: int|null}
     */
    public function getRt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getRtMetrics($from, $to, $agentId);
    }

    /**
     * Get ticket counts by status.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @return array{new: int, active: int, waiting: int, resolved: int, closed: int}
     */
    public function getCounts(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getCountsByStatus($from, $to, $agentId);
    }

    /**
     * Get ticket volume over time.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @return array<int, array{date: string, tickets_created: int, tickets_resolved: int}>
     */
    public function getVolume(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getVolumeOverTime($from, $to, $agentId);
    }

    /**
     * Get agent performance metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by specific agent
     * @return array<int, array{agent_id: int, tickets_assigned: int, tickets_resolved: int, avg_frt: float|null, avg_rt: float|null}>
     */
    public function getAgentPerformance(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getAgentPerformance($from, $to, $agentId);
    }

    /**
     * Validate date range.
     *
     * @throws \InvalidArgumentException If from is after to
     */
    private function validateDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($from >= $to) {
            throw new \InvalidArgumentException('"from" must be before "to"');
        }
    }
}
