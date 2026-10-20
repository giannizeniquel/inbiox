<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Compartido\Security\AlcanceDeResponsabilidad;
use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Disponibilidad\UseCase\QuitarDisponibilidadManualUseCase;
use App\Disponibilidad\UseCase\CreateTurnoUseCase;
use App\Disponibilidad\UseCase\DeleteTurnoUseCase;
use App\Disponibilidad\UseCase\SetDisponibilidadManualUseCase;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Agente\Repository\AgenteRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Agent availability and shift management endpoints.
 *
 * Thin controllers — all business logic is delegated to UseCases or Services.
 * No direct entityManager->persist(), no direct Mercure publishing.
 *
 * Authorization:
 * - All methods require ROLE_AGENT
 * - Agent can only view/manage their own availability
 * - Admin/Supervisor can view/manage any agent
 */
#[IsGranted('ROLE_AGENT')]
class DisponibilidadDeAgenteController
{
    public function __construct(
        private SetDisponibilidadManualUseCase $setDisponibilidadManualUseCase,
        private QuitarDisponibilidadManualUseCase $quitarDisponibilidadManualUseCase,
        private CreateTurnoUseCase $createTurnoUseCase,
        private DeleteTurnoUseCase $deleteTurnoUseCase,
        private DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
        private AgenteRepository $agentRepo,
        private TurnoRepository $turnoRepo,
        private TicketRepository $ticketRepo,
        private AlcanceDeResponsabilidad $ownershipScope,
    ) {}

    /**
     * POST /agents/{id}/availability
     *
     * Set an agent's availability override with optional TTL.
     * Returns 200 with the updated availability state.
     */
    #[Route('/api/agents/{id}/availability', name: 'app_agent_set_availability', methods: ['POST'])]
    public function setDisponibilidadManual(
        int $id,
        #[MapRequestPayload] SetDisponibilidadManualRequest $request,
    ): JsonResponse {
        // Ownership check: AGENT can only modify their own availability
        if (false === $this->isPermitidoParaAgente($id)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $agente = $this->agentRepo->findById($id);
        if ($agente === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        if ($request->isAvailable === null) {
            return new JsonResponse(['error' => 'is_available is required'], 422);
        }

        try {
            $result = $this->setDisponibilidadManualUseCase->execute(
                $id,
                $request->isAvailable,
                $request->ttlMinutes,
            );

            return new JsonResponse($result);
        } catch (LockWaitTimeoutException) {
            return new JsonResponse(['error' => 'Resource locked, try again'], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /agents/{id}/availability
     *
     * Clear an agent's availability override (reset to NULL).
     * Returns 204 on success.
     */
    #[Route('/api/agents/{id}/availability', name: 'app_agent_clear_availability', methods: ['DELETE'])]
    public function quitarDisponibilidadManual(int $id): JsonResponse
    {
        // Ownership check: AGENT can only modify their own availability
        if (false === $this->isPermitidoParaAgente($id)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $agente = $this->agentRepo->findById($id);
        if ($agente === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        try {
            $this->quitarDisponibilidadManualUseCase->execute($id);

            return new JsonResponse(null, 204);
        } catch (LockWaitTimeoutException) {
            return new JsonResponse(['error' => 'Resource locked, try again'], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /agents/{id}/availability
     *
     * Read the full availability state for an agent.
     * Reuses DisponibilidadDeAgenteService for determination. Read-only.
     */
    #[Route('/api/agents/{id}/availability', name: 'app_agent_get_availability', methods: ['GET'])]
    public function getDisponibilidad(int $id): JsonResponse
    {
        // Ownership check: AGENT can only view their own availability
        if (false === $this->isPermitidoParaAgente($id)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $agente = $this->agentRepo->findById($id);
        if ($agente === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $localTz = new \DateTimeZone($agente->getZonaHoraria());
        $localNow = $nowUtc->setTimezone($localTz);

        $isDisponible = $this->disponibilidadDeAgenteService->isDisponible($agente, $nowUtc);
        $cargaActual = $this->ticketRepo->countActivosDeAgente($id);

        // Compute is_in_shift by checking schedule only (no override, no capacity)
        $isEnTurno = $this->isAgenteEnTurno($agente, $localNow);

        // Compute override info
        $disponibilidadManualActiva = false;
        $disponibilidadManual = null;
        $disponibilidadManualVenceAt = null;

        if ($agente->getDisponibilidadManual() !== null) {
            if ($agente->getDisponibilidadManualHasta() === null || $agente->getDisponibilidadManualHasta() >= $nowUtc) {
                $disponibilidadManualActiva = true;
                $disponibilidadManual = $agente->getDisponibilidadManual();
                $disponibilidadManualVenceAt = $agente->getDisponibilidadManualHasta()?->format('Y-m-d\TH:i:s\Z');
            }
        }

        return new JsonResponse([
            'agent_id'     => $id,
            'is_available' => $isDisponible,
            'is_in_shift'  => $isEnTurno,
            'timezone'     => $agente->getZonaHoraria(),
            'current_load' => $cargaActual,
            'max_capacity' => $agente->getMaxTicketsActivos(),
            'override'     => [
                'active'       => $disponibilidadManualActiva,
                'is_available' => $disponibilidadManual,
                'expires_at'   => $disponibilidadManualVenceAt,
            ],
        ]);
    }

    /**
     * POST /agents/{id}/shifts
     *
     * Create a weekly shift for an agent.
     * Returns 201 with the created shift data.
     */
    #[Route('/api/agents/{id}/shifts', name: 'app_agent_create_shift', methods: ['POST'])]
    public function createTurno(
        int $id,
        #[MapRequestPayload] CreateTurnoRequest $request,
    ): JsonResponse {
        // Ownership check: AGENT can only modify their own shifts
        if (false === $this->isPermitidoParaAgente($id)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $agente = $this->agentRepo->findById($id);
        if ($agente === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        // DTO validation already ensures dayOfWeek 1-7 and HH:MM format.
        // Additional validation: start != end (DTO can't cross-validate fields).
        if ($request->startTime === $request->endTime) {
            return new JsonResponse(
                ['error' => 'start_time and end_time must differ'],
                422,
            );
        }

        try {
            $result = $this->createTurnoUseCase->execute(
                $id,
                $request->dayOfWeek,
                $request->startTime,
                $request->endTime,
            );

            return new JsonResponse($result, 201);
        } catch (LockWaitTimeoutException) {
            return new JsonResponse(['error' => 'Resource locked, try again'], 409);
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['error' => 'Duplicate shift: this shift already exists for this agent'], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /agents/{id}/shifts/{shiftId}
     *
     * Delete an agent's shift.
     * Returns 204 on success.
     */
    #[Route('/api/agents/{id}/shifts/{shiftId}', name: 'app_agent_delete_shift', methods: ['DELETE'])]
    public function deleteTurno(int $id, int $shiftId): JsonResponse
    {
        // Ownership check: AGENT can only modify their own shifts
        if (false === $this->isPermitidoParaAgente($id)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $agente = $this->agentRepo->findById($id);
        if ($agente === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $turno = $this->turnoRepo->find($shiftId);
        if ($turno === null) {
            return new JsonResponse(['error' => 'Shift not found'], 404);
        }

        $agenteDelTurno = $turno->getAgente();
        if ($agenteDelTurno === null || $agenteDelTurno->getId() !== $agente->getId()) {
            return new JsonResponse(['error' => 'Shift does not belong to this agent'], 404);
        }

        try {
            $this->deleteTurnoUseCase->execute($id, $shiftId);

            return new JsonResponse(null, 204);
        } catch (LockWaitTimeoutException) {
            return new JsonResponse(['error' => 'Resource locked, try again'], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Check if the current user is allowed to act on the given agent.
     *
     * ADMIN/SUPERVISOR can act on any agent.
     * AGENT can only act on their own agent.
     */
    private function isPermitidoParaAgente(int $agenteId): bool
    {
        $scopeId = $this->ownershipScope->getAgenteId();

        // null scope means SUPERVISOR/ADMIN (no scope restriction)
        if (null === $scopeId) {
            return true;
        }

        // AGENT: only their own agent
        return $scopeId === $agenteId;
    }

    /**
     * Check if an agent has a shift at the given local time.
     *
     * Reuses the same cross-midnight logic as DisponibilidadDeAgenteService
     * but checks schedule only — no override, no capacity.
     */
    private function isAgenteEnTurno(\App\Agente\Agente $agente, \DateTimeImmutable $localDateTime): bool
    {
        $dow = (int) $localDateTime->format('N');
        $time = $localDateTime->format('H:i:s');

        foreach ($agente->getTurnos() as $turno) {
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
                    return true; // First night
                }
                if ($dow === $nextDow && $time < $horaDeFin) {
                    return true; // Next morning
                }
            }
        }

        return false;
    }
}
