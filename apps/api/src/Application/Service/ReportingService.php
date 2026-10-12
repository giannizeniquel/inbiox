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
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array{avg_frt: float|null, min_frt: int|null, max_frt: int|null}
     */
    public function getFrt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getFrtMetrics($from, $to, $agentId, $phoneNumberId, $teamId);
    }

    /**
     * Get Resolution Time metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array{avg_rt: float|null, min_rt: int|null, max_rt: int|null}
     */
    public function getRt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getRtMetrics($from, $to, $agentId, $phoneNumberId, $teamId);
    }

    /**
     * Get ticket counts by status.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array{new: int, active: int, waiting: int, resolved: int, closed: int}
     */
    public function getCounts(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getCountsByStatus($from, $to, $agentId, $phoneNumberId, $teamId);
    }

    /**
     * Get ticket volume over time.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array<int, array{date: string, tickets_created: int, tickets_resolved: int}>
     */
    public function getVolume(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getVolumeOverTime($from, $to, $agentId, $phoneNumberId, $teamId);
    }

    /**
     * Get agent performance metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by specific agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array<int, array{agent_id: int, tickets_assigned: int, tickets_resolved: int, avg_frt: float|null, avg_rt: float|null}>
     */
    public function getAgentPerformance(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getAgentPerformance($from, $to, $agentId, $phoneNumberId, $teamId);
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
        return $this->reportingRepository->getAgentLoad($agentId, $phoneNumberId);
    }

    /**
     * Get tickets where assigned_agent_id IS NULL.
     *
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param \DateTimeImmutable|null $from Optional start of date range
     * @param \DateTimeImmutable|null $to Optional end of date range
     * @param int $limit Maximum results (default 50)
     * @return array{count: int, tickets: array}
     */
    public function getUnassigned(?string $phoneNumberId = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 50): array
    {
        return $this->reportingRepository->getUnassigned($phoneNumberId, $from, $to, $limit);
    }

    /**
     * Get agents currently available (in-shift and under capacity).
     *
     * Delegates to AgentAvailabilityService::listAvailableAgents().
     *
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{agent_id: int, agent_name: string, in_shift: bool, active_tickets: int}>
     */
    public function getAvailableAgents(?string $phoneNumberId = null): array
    {
        // This will be called from the controller, which injects AgentAvailabilityService
        // Service method is a thin pass-through
        return [];
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
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getFrtTrend($from, $to, $phoneNumberId);
    }

    /**
     * Get ticket volume grouped by day-of-week x hour for heatmap.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{day_of_week: int, hour: int, count: int}>
     */
    public function getHeatmap(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getHeatmap($from, $to, $phoneNumberId);
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
        return $this->reportingRepository->getRecurringCustomers($phoneNumberId, $threshold, $days);
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
        $this->validateDateRange($from, $to);

        return $this->reportingRepository->getReopened($from, $to, $phoneNumberId, $limit);
    }

    /**
     * Get aggregate + per-agent response-delay metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC) — filters on ticket episode start
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array{data_available_from: string|null, partial_data: bool, aggregate: array{avg_delay_seconds: float|null, sample_count: int, unanswered_inbound_count: int}, per_agent: array<int, array{agent_id: int, agent_name: string|null, avg_delay_seconds: float|null, sample_count: int}>}
     */
    public function getResponseDelay(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $this->validateDateRange($from, $to);

        $cutoff = $this->reportingRepository->getAttributionCutoff();
        $metrics = $this->reportingRepository->getResponseDelayMetrics($from, $to, $phoneNumberId);

        return [
            'data_available_from' => $cutoff?->format('Y-m-d H:i:s'),
            // Null cutoff means the whole messages table is unattributed —
            // that is partial data too, not "no data available at all".
            'partial_data' => $cutoff === null || $cutoff > $from,
            'aggregate' => $metrics['aggregate'],
            'per_agent' => $metrics['per_agent'],
        ];
    }

    /**
     * Get per-ticket participation drill-down.
     *
     * @param int $ticketId Ticket ID
     * @return array|null null when the ticket does not exist
     */
    public function getTicketParticipation(int $ticketId): ?array
    {
        $result = $this->reportingRepository->getTicketParticipation($ticketId);

        if ($result === null) {
            return null;
        }

        $result['data_available_from'] = $result['data_available_from'] instanceof \DateTimeImmutable
            ? $result['data_available_from']->format('Y-m-d H:i:s')
            : $result['data_available_from'];

        return $result;
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
