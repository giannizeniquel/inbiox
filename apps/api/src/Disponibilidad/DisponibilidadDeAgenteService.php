<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Disponibilidad\Query\DisponibilidadDeAgenteDTO;
use App\Agente\Agent;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Workspace;
use App\Agente\Repository\AgentRepository;
use App\Ticket\Repository\TicketRepository;

/**
 * Single source of truth for agent availability evaluation.
 *
 * Algorithm (4-step deterministic cascade):
 *   1. Account active check  → if inactive, return false
 *   2. Override check        → if active and not expired, return override value
 *   3. Schedule check        → convert UTC to agent local time, check shift match
 *   4. Capacity check        → COUNT active tickets, compare to max
 *   5. Default               → return true
 */
final class DisponibilidadDeAgenteService
{
    public function __construct(
        private AgentRepository $agentRepository,
        private TurnoRepository $agentShiftRepository,
        private TicketRepository $ticketRepository,
    ) {}

    /**
     * Evaluate whether a single agent is available at a given UTC moment.
     *
     * The agent's shifts are loaded from the entity (lazy-loads if not already
     * initialized). For batch evaluation, use listAvailableAgents().
     */
    public function isAvailable(Agent $agent, \DateTimeImmutable $atUtc): bool
    {
        // Step 1: Account active check
        if (!$agent->isActive()) {
            return false;
        }

        // Step 2: Override check (dominates schedule and capacity)
        if ($agent->getIsAvailableOverride() !== null) {
            if ($agent->getOverrideUntil() === null || $agent->getOverrideUntil() >= $atUtc) {
                return $agent->getIsAvailableOverride();
            }
        }

        // Step 3: Schedule check
        $localTime = $this->utcToLocal($atUtc, $agent->getTimezone());
        if (!$this->hasShiftAtTime($localTime, $agent->getShifts()->toArray())) {
            return false;
        }

        // Step 4: Capacity check
        $activeCount = $this->countActiveTickets($agent->getId());
        if ($activeCount >= $agent->getMaxActiveTickets()) {
            return false;
        }

        // Step 5: Available
        return true;
    }

    /**
     * Evaluate manual-assign eligibility ("Activo"/"Inactivo") for a single
     * agent at a given UTC moment.
     *
     * Narrower than isAvailable(): account-active pre-filter, then override
     * (if set and unexpired) takes precedence, ELSE shift-match. CAPACITY IS
     * NEVER CONSULTED — an in-shift agent at full capacity is still Activo.
     *
     * Shifts are passed explicitly so both the batch roster (preloaded via
     * findByAgents()) and a single-agent guard check can call this with no
     * N+1 query.
     *
     * @param Turno[] $shifts
     */
    public function isActiveNow(Agent $agent, array $shifts, \DateTimeImmutable $atUtc): bool
    {
        // Step 1: Account active check
        if (!$agent->isActive()) {
            return false;
        }

        // Step 2: Override check (dominates schedule; capacity never checked)
        if ($agent->getIsAvailableOverride() !== null) {
            if ($agent->getOverrideUntil() === null || $agent->getOverrideUntil() >= $atUtc) {
                return $agent->getIsAvailableOverride();
            }
        }

        // Step 3: Schedule check (capacity deliberately ignored)
        $localTime = $this->utcToLocal($atUtc, $agent->getTimezone());

        return $this->hasShiftAtTime($localTime, $shifts);
    }

    /**
     * Return all active agents that are available at a given UTC moment.
     *
     * Batch-loads shifts in one query (no N+1). Applies the full cascade in
     * PHP: override → schedule → capacity. Returns agents sorted by id ASC.
     *
     * @return Agent[]
     */
    public function listAvailableAgents(\DateTimeImmutable $atUtc): array
    {
        // 1. Fetch all active agents (1 query)
        $agents = $this->agentRepository->findAllActive();
        if (empty($agents)) {
            return [];
        }

        // 2. Batch-load shifts for all active agents (1 query, no N+1)
        $agentIds = \array_map(fn (Agent $a): ?int => $a->getId(), $agents);
        $allShifts = $this->agentShiftRepository->findByAgents(\array_filter($agentIds));

        /** @var array<int, Turno[]> $shiftsByAgent */
        $shiftsByAgent = [];
        foreach ($allShifts as $shift) {
            $agentId = $shift->getAgent()?->getId();
            if ($agentId !== null) {
                $shiftsByAgent[$agentId][] = $shift;
            }
        }

        // 3+4. Apply cascade per agent
        $available = [];
        foreach ($agents as $agent) {
            $agentId = $agent->getId();

            // Override check
            if ($agent->getIsAvailableOverride() !== null) {
                if ($agent->getOverrideUntil() === null || $agent->getOverrideUntil() >= $atUtc) {
                    if ($agent->getIsAvailableOverride()) {
                        $available[] = $agent;
                    }
                    continue;
                }
            }

            // Schedule check (uses preloaded shifts — no lazy load)
            $localTime = $this->utcToLocal($atUtc, $agent->getTimezone());
            if (!$this->hasShiftAtTime($localTime, $shiftsByAgent[$agentId] ?? [])) {
                continue;
            }

            // Capacity check
            $activeCount = $this->countActiveTickets($agentId);
            if ($activeCount >= $agent->getMaxActiveTickets()) {
                continue;
            }

            $available[] = $agent;
        }

        // Sort by id ASC (stable order)
        \usort($available, fn (Agent $a, Agent $b): int => $a->getId() <=> $b->getId());

        return $available;
    }

    /**
     * Return the FULL manual-assign roster for a workspace: every
     * `active = true` agent (an `active = false` agent NEVER appears),
     * each carrying a computed `is_active_now` (Activo/Inactivo) flag.
     *
     * Unlike listAvailableAgents()/listAvailableAgentsInWorkspace() (removed),
     * this does NOT filter agents out — it always returns the roster so the
     * UI can show who exists and why they are or aren't currently assignable.
     * Capacity is NEVER consulted for eligibility (only reported for display).
     *
     * @return DisponibilidadDeAgenteDTO[]
     */
    public function listWorkspaceAgentRoster(Workspace $workspace, \DateTimeImmutable $atUtc): array
    {
        // 1. Fetch all workspace agents, then filter to active=true in PHP
        $agents = $this->agentRepository->findByWorkspace($workspace);
        $activeAgents = \array_values(\array_filter($agents, fn (Agent $a): bool => $a->isActive()));

        if (empty($activeAgents)) {
            return [];
        }

        // 2. Batch-load shifts for the active agents only (1 query, no N+1)
        $agentIds = \array_map(fn (Agent $a): ?int => $a->getId(), $activeAgents);
        $allShifts = $this->agentShiftRepository->findByAgents(\array_filter($agentIds));

        /** @var array<int, Turno[]> $shiftsByAgent */
        $shiftsByAgent = [];
        foreach ($allShifts as $shift) {
            $agentId = $shift->getAgent()?->getId();
            if ($agentId !== null) {
                $shiftsByAgent[$agentId][] = $shift;
            }
        }

        // 3. Build the full roster (no eligibility-based exclusion)
        $roster = [];
        foreach ($activeAgents as $agent) {
            $agentId = $agent->getId();
            $isActiveNow = $this->isActiveNow($agent, $shiftsByAgent[$agentId] ?? [], $atUtc);
            $activeCount = $this->countActiveTickets($agentId);

            $roster[] = DisponibilidadDeAgenteDTO::fromEntity($agent, $activeCount, $isActiveNow);
        }

        // 4. Sort: Activo first, then id ASC (stable order within each tier)
        \usort($roster, function (DisponibilidadDeAgenteDTO $a, DisponibilidadDeAgenteDTO $b): int {
            if ($a->isActiveNow !== $b->isActiveNow) {
                return $a->isActiveNow ? -1 : 1;
            }

            return $a->id <=> $b->id;
        });

        return $roster;
    }

    /**
     * Check whether an agent is currently in their scheduled shift (schedule only).
     *
     * This is a SCHEDULE-ONLY check — it does NOT consider override or capacity.
     * Used by reports and inbox enrichment for read-only projections.
     * The full availability cascade is isAvailable().
     */
    public function isInShift(Agent $agent, \DateTimeImmutable $atUtc): bool
    {
        if (!$agent->isActive()) {
            return false;
        }

        $localTime = $this->utcToLocal($atUtc, $agent->getTimezone());

        return $this->hasShiftAtTime($localTime, $agent->getShifts()->toArray());
    }

    /**
     * Convert a UTC DateTimeImmutable to the agent's local timezone.
     */
    private function utcToLocal(\DateTimeImmutable $utc, string $timezoneName): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($timezoneName);

        return $utc->setTimezone($tz);
    }

    /**
     * Check whether any shift covers the given local time.
     *
     * Handles both normal shifts (end > start) and cross-midnight shifts
     * (end < start, e.g. 22:00-06:00). The interval is half-open: [start, end).
     *
     * @param Turno[] $shifts
     */
    private function hasShiftAtTime(\DateTimeImmutable $localDateTime, array $shifts): bool
    {
        $dow = (int) $localDateTime->format('N'); // 1=Monday, 7=Sunday
        $time = $localDateTime->format('H:i:s');

        foreach ($shifts as $shift) {
            $startTime = $shift->getStartTime()->format('H:i:s');
            $endTime = $shift->getEndTime()->format('H:i:s');

            if ($endTime > $startTime) {
                // Normal shift (same day, half-open [start, end))
                if ($shift->getDayOfWeek() === $dow && $time >= $startTime && $time < $endTime) {
                    return true;
                }
            } else {
                // Cross-midnight shift (end < start, e.g. 22:00-06:00)
                $nextDow = $shift->getDayOfWeek() === 7 ? 1 : $shift->getDayOfWeek() + 1;

                if ($dow === $shift->getDayOfWeek() && $time >= $startTime) {
                    return true; // First night: e.g., Fri 22:00-23:59
                }
                if ($dow === $nextDow && $time < $endTime) {
                    return true; // Next morning: e.g., Sat 00:00-05:59
                }
            }
        }

        return false;
    }

    /**
     * Count active tickets assigned to an agent.
     *
     * Active tickets are those with status IN ('new', 'active', 'waiting').
     * Uses index idx_tickets_owner_status (assigned_agent_id, status).
     */
    private function countActiveTickets(?int $agentId): int
    {
        if ($agentId === null) {
            return 0;
        }

        return $this->ticketRepository->countActivosDeAgente($agentId);
    }
}
