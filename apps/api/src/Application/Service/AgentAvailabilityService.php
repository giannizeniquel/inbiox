<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentShift;
use App\Domain\Agent\AgentShiftRepository;
use App\Domain\Ticket\TicketStatus;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;

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
final class AgentAvailabilityService
{
    public function __construct(
        private AgentRepository $agentRepository,
        private AgentShiftRepository $agentShiftRepository,
        private EntityManagerInterface $entityManager,
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

        /** @var array<int, AgentShift[]> $shiftsByAgent */
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
     * @param AgentShift[] $shifts
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

        $result = $this->entityManager->createQuery(
            'SELECT COUNT(t.id) FROM App\Domain\Ticket\Ticket t
             WHERE t.assignedAgentId = :agentId
             AND t.status IN (:statuses)',
        )
            ->setParameter('agentId', $agentId)
            ->setParameter('statuses', [
                TicketStatus::NEW->value,
                TicketStatus::ACTIVE->value,
                TicketStatus::WAITING->value,
            ])
            ->getSingleScalarResult();

        return (int) $result;
    }
}
