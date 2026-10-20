<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Disponibilidad\Query\DisponibilidadDeAgenteDTO;
use App\Agente\Agente;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Ticket\EstadoDeTicket;
use App\Administracion\EspacioDeTrabajo;
use App\Agente\Repository\AgenteRepository;
use App\Ticket\Repository\TicketRepository;

/**
 * Fuente única de verdad para evaluar la disponibilidad de un agente.
 *
 * Algoritmo (cascada determinista de 4 pasos):
 *   1. Cuenta activa         → si está inactiva, devuelve false
 *   2. Override              → si está activo y no venció, devuelve el valor del override
 *   3. Horario               → convierte UTC a la hora local del agente y verifica si coincide con un turno
 *   4. Capacidad             → COUNT de tickets activos, compara con el máximo
 *   5. Por defecto           → devuelve true
 */
final class DisponibilidadDeAgenteService
{
    public function __construct(
        private AgenteRepository $agentRepository,
        private TurnoRepository $turnoRepository,
        private TicketRepository $ticketRepository,
    ) {}

    /**
     * Evalúa si un agente está disponible en un momento UTC dado.
     *
     * Los turnos del agente se cargan desde la entidad (con carga diferida si aún no
     * están inicializados). Para evaluar en lote, usar listarAgentesDisponibles().
     */
    public function isDisponible(Agente $agente, \DateTimeImmutable $atUtc): bool
    {
        // Paso 1: cuenta activa
        if (!$agente->isActivo()) {
            return false;
        }

        // Paso 2: override (prevalece sobre el horario y la capacidad)
        if ($agente->getDisponibilidadManual() !== null) {
            if ($agente->getDisponibilidadManualHasta() === null || $agente->getDisponibilidadManualHasta() >= $atUtc) {
                return $agente->getDisponibilidadManual();
            }
        }

        // Paso 3: horario
        $localTime = $this->utcToLocal($atUtc, $agente->getZonaHoraria());
        if (!$this->hasTurnoEnHorario($localTime, $agente->getTurnos()->toArray())) {
            return false;
        }

        // Paso 4: capacidad
        $cantidadDeActivos = $this->countTicketsActivos($agente->getId());
        if ($cantidadDeActivos >= $agente->getMaxTicketsActivos()) {
            return false;
        }

        // Paso 5: disponible
        return true;
    }

    /**
     * Evalúa la elegibilidad para asignación manual ("Activo"/"Inactivo") de un
     * agente en un momento UTC dado.
     *
     * Más acotado que isDisponible(): primero filtra por cuenta activa, luego el override
     * (si está definido y no venció) tiene prioridad; SI NO, la coincidencia de turno. LA CAPACIDAD
     * NUNCA SE CONSULTA: un agente en turno con la capacidad completa sigue Activo.
     *
     * Los turnos se pasan explícitamente para que tanto el listado en lote (precargado con
     * findByAgentes()) como una verificación de guarda de un solo agente puedan llamarlo sin
     * una query N+1.
     *
     * @param Turno[] $turnos
     */
    public function isActivoAhora(Agente $agente, array $turnos, \DateTimeImmutable $atUtc): bool
    {
        // Paso 1: cuenta activa
        if (!$agente->isActivo()) {
            return false;
        }

        // Paso 2: override (prevalece sobre el horario; la capacidad nunca se verifica)
        if ($agente->getDisponibilidadManual() !== null) {
            if ($agente->getDisponibilidadManualHasta() === null || $agente->getDisponibilidadManualHasta() >= $atUtc) {
                return $agente->getDisponibilidadManual();
            }
        }

        // Paso 3: horario (la capacidad se ignora a propósito)
        $localTime = $this->utcToLocal($atUtc, $agente->getZonaHoraria());

        return $this->hasTurnoEnHorario($localTime, $turnos);
    }

    /**
     * Devuelve todos los agentes activos que están disponibles en un momento UTC dado.
     *
     * Carga los turnos en lote en una sola query (sin N+1). Aplica la cascada completa en
     * PHP: override → horario → capacidad. Devuelve los agentes ordenados por id ASC.
     *
     * @return Agente[]
     */
    public function listarAgentesDisponibles(\DateTimeImmutable $atUtc): array
    {
        // 1. Obtiene todos los agentes activos (1 query)
        $agentes = $this->agentRepository->findActivos();
        if (empty($agentes)) {
            return [];
        }

        // 2. Carga en lote los turnos de todos los agentes activos (1 query, sin N+1)
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

        // 3+4. Aplica la cascada por agente
        $disponible = [];
        foreach ($agentes as $agente) {
            $agenteId = $agente->getId();

            // Verificación del override
            if ($agente->getDisponibilidadManual() !== null) {
                if ($agente->getDisponibilidadManualHasta() === null || $agente->getDisponibilidadManualHasta() >= $atUtc) {
                    if ($agente->getDisponibilidadManual()) {
                        $disponible[] = $agente;
                    }
                    continue;
                }
            }

            // Verificación de horario (usa los turnos precargados, sin carga diferida)
            $localTime = $this->utcToLocal($atUtc, $agente->getZonaHoraria());
            if (!$this->hasTurnoEnHorario($localTime, $turnosPorAgente[$agenteId] ?? [])) {
                continue;
            }

            // Verificación de capacidad
            $cantidadDeActivos = $this->countTicketsActivos($agenteId);
            if ($cantidadDeActivos >= $agente->getMaxTicketsActivos()) {
                continue;
            }

            $disponible[] = $agente;
        }

        // Ordena por id ASC (orden estable)
        \usort($disponible, fn (Agente $a, Agente $b): int => $a->getId() <=> $b->getId());

        return $disponible;
    }

    /**
     * Devuelve el listado COMPLETO para asignación manual de un espacio de trabajo: cada
     * agente con `active = true` (un agente con `active = false` NUNCA aparece),
     * cada uno con un indicador calculado `is_active_now` (Activo/Inactivo).
     *
     * A diferencia de listarAgentesDisponibles()/listAvailableAgentsInWorkspace() (eliminado),
     * esto NO excluye agentes: siempre devuelve el listado para que la
     * UI pueda mostrar quién existe y por qué puede o no recibir asignaciones en este momento.
     * La capacidad NUNCA se consulta para la elegibilidad (solo se informa para mostrarla).
     *
     * @return DisponibilidadDeAgenteDTO[]
     */
    public function listarAgentesDelEspacioDeTrabajo(EspacioDeTrabajo $espacioDeTrabajo, \DateTimeImmutable $atUtc): array
    {
        // 1. Obtiene todos los agentes del espacio de trabajo y luego filtra active=true en PHP
        $agentes = $this->agentRepository->findByEspacioDeTrabajo($espacioDeTrabajo);
        $agentesActivos = \array_values(\array_filter($agentes, fn (Agente $a): bool => $a->isActivo()));

        if (empty($agentesActivos)) {
            return [];
        }

        // 2. Carga en lote los turnos solo de los agentes activos (1 query, sin N+1)
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

        // 3. Arma el listado completo (sin exclusiones por elegibilidad)
        $agentesDelEspacioDeTrabajo = [];
        foreach ($agentesActivos as $agente) {
            $agenteId = $agente->getId();
            $isActivoAhora = $this->isActivoAhora($agente, $turnosPorAgente[$agenteId] ?? [], $atUtc);
            $cantidadDeActivos = $this->countTicketsActivos($agenteId);

            $agentesDelEspacioDeTrabajo[] = DisponibilidadDeAgenteDTO::fromEntity($agente, $cantidadDeActivos, $isActivoAhora);
        }

        // 4. Ordena: Activo primero, luego id ASC (orden estable dentro de cada grupo)
        \usort($agentesDelEspacioDeTrabajo, function (DisponibilidadDeAgenteDTO $a, DisponibilidadDeAgenteDTO $b): int {
            if ($a->isActivoAhora !== $b->isActivoAhora) {
                return $a->isActivoAhora ? -1 : 1;
            }

            return $a->id <=> $b->id;
        });

        return $agentesDelEspacioDeTrabajo;
    }

    /**
     * Verifica si un agente está actualmente dentro de su turno programado (solo horario).
     *
     * Es una verificación SOLO DE HORARIO: NO considera el override ni la capacidad.
     * La usan los reportes y el enriquecimiento del inbox para proyecciones de solo lectura.
     * La cascada completa de disponibilidad es isDisponible().
     */
    public function isEnTurno(Agente $agente, \DateTimeImmutable $atUtc): bool
    {
        if (!$agente->isActivo()) {
            return false;
        }

        $localTime = $this->utcToLocal($atUtc, $agente->getZonaHoraria());

        return $this->hasTurnoEnHorario($localTime, $agente->getTurnos()->toArray());
    }

    /**
     * Convierte un DateTimeImmutable en UTC a la zona horaria local del agente.
     */
    private function utcToLocal(\DateTimeImmutable $utc, string $timezoneName): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($timezoneName);

        return $utc->setTimezone($tz);
    }

    /**
     * Verifica si algún turno cubre la hora local dada.
     *
     * Contempla tanto turnos normales (end > start) como turnos que cruzan la medianoche
     * (end < start, p. ej. 22:00-06:00). El intervalo es semiabierto: [start, end).
     *
     * @param Turno[] $turnos
     */
    private function hasTurnoEnHorario(\DateTimeImmutable $localDateTime, array $turnos): bool
    {
        $dow = (int) $localDateTime->format('N'); // 1=lunes, 7=domingo
        $time = $localDateTime->format('H:i:s');

        foreach ($turnos as $turno) {
            $horaDeInicio = $turno->getHoraDeInicio()->format('H:i:s');
            $horaDeFin = $turno->getHoraDeFin()->format('H:i:s');

            if ($horaDeFin > $horaDeInicio) {
                // Turno normal (mismo día, semiabierto [start, end))
                if ($turno->getDiaDeLaSemana() === $dow && $time >= $horaDeInicio && $time < $horaDeFin) {
                    return true;
                }
            } else {
                // Turno que cruza la medianoche (end < start, p. ej. 22:00-06:00)
                $nextDow = $turno->getDiaDeLaSemana() === 7 ? 1 : $turno->getDiaDeLaSemana() + 1;

                if ($dow === $turno->getDiaDeLaSemana() && $time >= $horaDeInicio) {
                    return true; // Primera noche: p. ej., vie 22:00-23:59
                }
                if ($dow === $nextDow && $time < $horaDeFin) {
                    return true; // Mañana siguiente: p. ej., sáb 00:00-05:59
                }
            }
        }

        return false;
    }

    /**
     * Cuenta los tickets activos asignados a un agente.
     *
     * Los tickets activos son los que tienen status IN ('nuevo', 'activo', 'en_espera').
     * Usa el índice idx_tickets_owner_status (assigned_agent_id, status).
     */
    private function countTicketsActivos(?int $agenteId): int
    {
        if ($agenteId === null) {
            return 0;
        }

        return $this->ticketRepository->countActivosDeAgente($agenteId);
    }
}
