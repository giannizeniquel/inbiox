<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Disponibilidad\Query\DisponibilidadDeAgenteDTO;
use App\Agente\Agente;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Workspace;
use App\Agente\Repository\AgenteRepository;
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
        private AgenteRepository $agentRepository,
        private TurnoRepository $turnoRepository,
        private TicketRepository $ticketRepository,
    ) {}

    /**
     * Evaluate whether a single agent is available at a given UTC moment.
     *
     * The agent's shifts are loaded from the entity (lazy-loads if not already
     * initialized). For batch evaluation, use listarAgentesDisponibles().
     */
    public function isDisponible(Agente $agente, \DateTimeImmutable $atUtc): bool
    {
        // Step 1: Account active check
        if (!$agente->isActive()) {
            return false;
        }

        // Step 2: Override check (dominates schedule and capacity)
        if ($agente->getIsAvailableOverride() !== null) {
            if ($agente->getOverrideUntil() === null || $agente->getOverrideUntil() >= $atUtc) {
                return $agente->getIsAvailableOverride();
            }
        }

        // Step 3: Schedule check
        $localTime = $this->utcToLocal($atUtc, $agente->getTimezone());
        if (!$this->hasTurnoEnHorario($localTime, $agente->getShifts()->toArray())) {
            return false;
        }

        // Step 4: Capacity check
        $cantidadDeActivos = $this->countTicketsActivos($agente->getId());
        if ($cantidadDeActivos >= $agente->getMaxActiveTickets()) {
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
     * findByAgentes()) and a single-agent guard check can call this with no
     * N+1 query.
     *
     * @param Turno[] $turnos
     */
    public function isActivoAhora(Agente $agente, array $turnos, \DateTimeImmutable $atUtc): bool
    {
        // Step 1: Account active check
        if (!$agente->isActive()) {
            return false;
        }

        // Step 2: Override check (dominates schedule; capacity never checked)
        if ($agente->getIsAvailableOverride() !== null) {
            if ($agente->getOverrideUntil() === null || $agente->getOverrideUntil() >= $atUtc) {
                return $agente->getIsAvailableOverride();
            }
        }

        // Step 3: Schedule check (capacity deliberately ignored)
        $localTime = $this->utcToLocal($atUtc, $agente->getTimezone());

        return $this->hasTurnoEnHorario($localTime, $turnos);
    }

    /**
     * Return all active agents that are available at a given UTC moment.
     *
     * Batch-loads shifts in one query (no N+1). Applies the full cascade in
     * PHP: override → schedule → capacity. Returns agents sorted by id ASC.
     *
     * @return Agente[]
     */
    public function listarAgentesDisponibles(\DateTimeImmutable $atUtc): array
    {
        // 1. Fetch all active agents (1 query)
        $agentes = $this->agentRepository->findAllActive();
        if (empty($agentes)) {
            return [];
        }

        // 2. Batch-load shifts for all active agents (1 query, no N+1)
        $agenteIds = \array_map(fn (Agente $a): ?int => $a->getId(), $agentes);
        $todosLosTurnos = $this->turnoRepository->findByAgentes(\array_filter($agenteIds));

        /** @var array<int, Turno[]> $turnosPorAgente */
        $turnosPorAgente = [];
        foreach ($todosLosTurnos as $turno) {
            $agenteId = $turno->getAgente()?->getId();
            if ($agenteId !== null) {
                $turnosPorAgente[$agenteId][] = $turno;
            }
        }

        // 3+4. Apply cascade per agent
        $disponible = [];
        foreach ($agentes as $agente) {
            $agenteId = $agente->getId();

            // Override check
            if ($agente->getIsAvailableOverride() !== null) {
                if ($agente->getOverrideUntil() === null || $agente->getOverrideUntil() >= $atUtc) {
                    if ($agente->getIsAvailableOverride()) {
                        $disponible[] = $agente;
                    }
                    continue;
                }
            }

            // Schedule check (uses preloaded shifts — no lazy load)
            $localTime = $this->utcToLocal($atUtc, $agente->getTimezone());
            if (!$this->hasTurnoEnHorario($localTime, $turnosPorAgente[$agenteId] ?? [])) {
                continue;
            }

            // Capacity check
            $cantidadDeActivos = $this->countTicketsActivos($agenteId);
            if ($cantidadDeActivos >= $agente->getMaxActiveTickets()) {
                continue;
            }

            $disponible[] = $agente;
        }

        // Sort by id ASC (stable order)
        \usort($disponible, fn (Agente $a, Agente $b): int => $a->getId() <=> $b->getId());

        return $disponible;
    }

    /**
     * Return the FULL manual-assign roster for a workspace: every
     * `active = true` agent (an `active = false` agent NEVER appears),
     * each carrying a computed `is_active_now` (Activo/Inactivo) flag.
     *
     * Unlike listarAgentesDisponibles()/listAvailableAgentsInWorkspace() (removed),
     * this does NOT filter agents out — it always returns the roster so the
     * UI can show who exists and why they are or aren't currently assignable.
     * Capacity is NEVER consulted for eligibility (only reported for display).
     *
     * @return DisponibilidadDeAgenteDTO[]
     */
    public function listarAgentesDelEspacioDeTrabajo(Workspace $espacioDeTrabajo, \DateTimeImmutable $atUtc): array
    {
        // 1. Fetch all workspace agents, then filter to active=true in PHP
        $agentes = $this->agentRepository->findByWorkspace($espacioDeTrabajo);
        $agentesActivos = \array_values(\array_filter($agentes, fn (Agente $a): bool => $a->isActive()));

        if (empty($agentesActivos)) {
            return [];
        }

        // 2. Batch-load shifts for the active agents only (1 query, no N+1)
        $agenteIds = \array_map(fn (Agente $a): ?int => $a->getId(), $agentesActivos);
        $todosLosTurnos = $this->turnoRepository->findByAgentes(\array_filter($agenteIds));

        /** @var array<int, Turno[]> $turnosPorAgente */
        $turnosPorAgente = [];
        foreach ($todosLosTurnos as $turno) {
            $agenteId = $turno->getAgente()?->getId();
            if ($agenteId !== null) {
                $turnosPorAgente[$agenteId][] = $turno;
            }
        }

        // 3. Build the full roster (no eligibility-based exclusion)
        $agentesDelEspacioDeTrabajo = [];
        foreach ($agentesActivos as $agente) {
            $agenteId = $agente->getId();
            $isActivoAhora = $this->isActivoAhora($agente, $turnosPorAgente[$agenteId] ?? [], $atUtc);
            $cantidadDeActivos = $this->countTicketsActivos($agenteId);

            $agentesDelEspacioDeTrabajo[] = DisponibilidadDeAgenteDTO::fromEntity($agente, $cantidadDeActivos, $isActivoAhora);
        }

        // 4. Sort: Activo first, then id ASC (stable order within each tier)
        \usort($agentesDelEspacioDeTrabajo, function (DisponibilidadDeAgenteDTO $a, DisponibilidadDeAgenteDTO $b): int {
            if ($a->isActivoAhora !== $b->isActivoAhora) {
                return $a->isActivoAhora ? -1 : 1;
            }

            return $a->id <=> $b->id;
        });

        return $agentesDelEspacioDeTrabajo;
    }

    /**
     * Check whether an agent is currently in their scheduled shift (schedule only).
     *
     * This is a SCHEDULE-ONLY check — it does NOT consider override or capacity.
     * Used by reports and inbox enrichment for read-only projections.
     * The full availability cascade is isAvailable().
     */
    public function isEnTurno(Agente $agente, \DateTimeImmutable $atUtc): bool
    {
        if (!$agente->isActive()) {
            return false;
        }

        $localTime = $this->utcToLocal($atUtc, $agente->getTimezone());

        return $this->hasTurnoEnHorario($localTime, $agente->getShifts()->toArray());
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
     * @param Turno[] $turnos
     */
    private function hasTurnoEnHorario(\DateTimeImmutable $localDateTime, array $turnos): bool
    {
        $dow = (int) $localDateTime->format('N'); // 1=Monday, 7=Sunday
        $time = $localDateTime->format('H:i:s');

        foreach ($turnos as $turno) {
            $horaDeInicio = $turno->getHoraDeInicio()->format('H:i:s');
            $horaDeFin = $turno->getHoraDeFin()->format('H:i:s');

            if ($horaDeFin > $horaDeInicio) {
                // Normal shift (same day, half-open [start, end))
                if ($turno->getDiaDeLaSemana() === $dow && $time >= $horaDeInicio && $time < $horaDeFin) {
                    return true;
                }
            } else {
                // Cross-midnight shift (end < start, e.g. 22:00-06:00)
                $nextDow = $turno->getDiaDeLaSemana() === 7 ? 1 : $turno->getDiaDeLaSemana() + 1;

                if ($dow === $turno->getDiaDeLaSemana() && $time >= $horaDeInicio) {
                    return true; // First night: e.g., Fri 22:00-23:59
                }
                if ($dow === $nextDow && $time < $horaDeFin) {
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
    private function countTicketsActivos(?int $agenteId): int
    {
        if ($agenteId === null) {
            return 0;
        }

        return $this->ticketRepository->countActivosDeAgente($agenteId);
    }
}
