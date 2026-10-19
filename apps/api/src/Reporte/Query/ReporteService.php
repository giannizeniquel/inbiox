<?php

declare(strict_types=1);

namespace App\Reporte\Query;

use App\Agente\Repository\AgenteRepository;
use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Reporte\Repository\ReporteRepository;

/**
 * Service for reporting and metrics queries.
 *
 * Orchestrates reporting data retrieval and formats responses.
 * All dates must be in UTC.
 *
 * Ownership scoping:
 * - All methods accept an optional ?int $agentId parameter for query-level filtering
 * - When set, all results are scoped to the given agent
 * - The controller (ReporteController) decides the scope based on user role
 */
class ReporteService
{
    public function __construct(
        private ReporteRepository $reportingRepository,
        private AgenteRepository $agentRepository,
        private DisponibilidadDeAgenteService $agentAvailabilityService,
    ) {}

    /**
     * Get First Response Time metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array{avg_frt_seconds: float|null, min_frt_seconds: int|null, max_frt_seconds: int|null, avg_frt_minutes: float|null, min_frt_minutes: int|null, max_frt_minutes: int|null}
     */
    public function getFrt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reportingRepository->getMetricasDeFrt($from, $to, $agentId, $phoneNumberId, $teamId);
    }

    /**
     * Get Resolution Time metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by assigned agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array{avg_rt_seconds: float|null, min_rt_seconds: int|null, max_rt_seconds: int|null, avg_rt_minutes: float|null, min_rt_minutes: int|null, max_rt_minutes: int|null}
     */
    public function getRt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reportingRepository->getMetricasDeRt($from, $to, $agentId, $phoneNumberId, $teamId);
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
    public function getConteos(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reportingRepository->getConteosPorEstado($from, $to, $agentId, $phoneNumberId, $teamId);
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
    public function getVolumen(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reportingRepository->getVolumenEnElTiempo($from, $to, $agentId, $phoneNumberId, $teamId);
    }

    /**
     * Get agent performance metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param int|null $agentId Optional filter by specific agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int|null $teamId Optional filter by team (single-select)
     * @return array<int, array{agent_id: int, agent_name: string|null, tickets_assigned: int, tickets_resolved: int, current_active_tickets: int, max_active_tickets: int, current_load: float, is_in_shift: bool, avg_frt_seconds: float|null, avg_frt_minutes: float|null, avg_rt_seconds: float|null, avg_rt_minutes: float|null}>
     */
    public function getRendimientoDeAgentes(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null, ?int $teamId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        $rows = $this->reportingRepository->getRendimientoDeAgentes($from, $to, $agentId, $phoneNumberId, $teamId);

        return $this->addEnTurno($rows);
    }

    /**
     * Add `is_in_shift` (schedule only, evaluated now) right after
     * `current_load` in each agent row. An agent that cannot be loaded is
     * reported as not in shift.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function addEnTurno(array $rows): array
    {
        $now = new \DateTimeImmutable();
        $agentMap = [];
        if (!empty($rows)) {
            $agentIds = array_map(fn($r) => (int) $r['agent_id'], $rows);
            foreach ($this->agentRepository->findByIds($agentIds) as $agent) {
                $agentMap[$agent->getId()] = $agent;
            }
        }

        $enriched = [];
        foreach ($rows as $row) {
            $isInShift = false;
            if (isset($agentMap[$row['agent_id']])) {
                $isInShift = $this->agentAvailabilityService->isEnTurno($agentMap[$row['agent_id']], $now);
            }

            $enrichedRow = [];
            foreach ($row as $key => $value) {
                $enrichedRow[$key] = $value;
                if ($key === 'current_load') {
                    $enrichedRow['is_in_shift'] = $isInShift;
                }
            }
            $enriched[] = $enrichedRow;
        }

        return $enriched;
    }

    /**
     * Get per-agent active ticket count vs max capacity.
     *
     * @param int|null $agentId Optional filter by specific agent
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{agent_id: int, agent_name: string, active_tickets: int, max_capacity: int, load_percent: float}>
     */
    public function getCargaDeAgentes(?int $agentId = null, ?string $phoneNumberId = null): array
    {
        return $this->reportingRepository->getCargaDeAgentes($agentId, $phoneNumberId);
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
    public function getSinAsignar(?string $phoneNumberId = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 50): array
    {
        return $this->reportingRepository->getSinAsignar($phoneNumberId, $from, $to, $limit);
    }

    /**
     * Get FRT aggregated by day within a date range.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{date: string, avg_frt_seconds: float, ticket_count: int}>
     */
    public function getTendenciaDeFrt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reportingRepository->getTendenciaDeFrt($from, $to, $phoneNumberId);
    }

    /**
     * Get ticket volume grouped by day-of-week x hour for heatmap.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC)
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array<int, array{day_of_week: int, hour: int, count: int}>
     */
    public function getMapaDeCalor(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reportingRepository->getMapaDeCalor($from, $to, $phoneNumberId);
    }

    /**
     * Get contacts with >= threshold tickets in the last N days.
     *
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @param int $threshold Minimum ticket count (default 3)
     * @param int $days Lookback window in days (default 90)
     * @return array<int, array{contact_id: int, contact_name: string|null, email: string|null, ticket_count: int, last_ticket_at: string}>
     */
    public function getContactosRecurrentes(?string $phoneNumberId = null, int $threshold = 3, int $days = 90): array
    {
        return $this->reportingRepository->getContactosRecurrentes($phoneNumberId, $threshold, $days);
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
    public function getReabiertos(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null, int $limit = 100): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reportingRepository->getReabiertos($from, $to, $phoneNumberId, $limit);
    }

    /**
     * Get aggregate + per-agent response-delay metrics.
     *
     * @param \DateTimeImmutable $from Start of date range (UTC) — filters on ticket episode start
     * @param \DateTimeImmutable $to End of date range (UTC)
     * @param string|null $phoneNumberId Optional filter by workspace phone_number_id
     * @return array{data_available_from: string|null, partial_data: bool, aggregate: array{avg_delay_seconds: float|null, sample_count: int, unanswered_inbound_count: int}, per_agent: array<int, array{agent_id: int, agent_name: string|null, avg_delay_seconds: float|null, sample_count: int}>}
     */
    public function getDemoraDeRespuesta(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        $cutoff = $this->reportingRepository->getCorteDeAtribucion();
        $metrics = $this->reportingRepository->getMetricasDeDemoraDeRespuesta($from, $to, $phoneNumberId);

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
     * @return array{ticket_id: int, data_available_from: string|null, partial_data: bool, participants: array<int, array{agent_id: int, agent_name: string|null, message_count: int, sample_count: int, avg_delay_seconds: float|null}>, automatic_message_count: int, unanswered_inbound_count: int, pairs: array<int, array{run_start_at: string, reply_at: string|null, agent_id: int|null, delay_seconds: float|null}>}|null null when the ticket does not exist or has no conversation
     */
    public function getParticipacionEnTicket(int $ticketId): ?array
    {
        $result = $this->reportingRepository->getParticipacionEnTicket($ticketId);

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
    private function validarRangoDeFechas(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($from >= $to) {
            throw new \InvalidArgumentException('"from" must be before "to"');
        }
    }
}
