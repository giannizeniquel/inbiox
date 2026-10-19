<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Compartido\Security\OwnershipScope;
use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Disponibilidad\UseCase\QuitarDisponibilidadManualUseCase;
use App\Disponibilidad\UseCase\CreateTurnoUseCase;
use App\Disponibilidad\UseCase\DeleteTurnoUseCase;
use App\Disponibilidad\UseCase\SetDisponibilidadManualUseCase;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Agente\Repository\AgentRepository;
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
        private SetDisponibilidadManualUseCase $setAgentAvailabilityUseCase,
        private QuitarDisponibilidadManualUseCase $clearAgentAvailabilityUseCase,
        private CreateTurnoUseCase $createAgentShiftUseCase,
        private DeleteTurnoUseCase $deleteAgentShiftUseCase,
        private DisponibilidadDeAgenteService $agentAvailabilityService,
        private AgentRepository $agentRepo,
        private TurnoRepository $agentShiftRepo,
        private TicketRepository $ticketRepo,
        private OwnershipScope $ownershipScope,
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

        $agent = $this->agentRepo->findById($id);
        if ($agent === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        if ($request->isAvailable === null) {
            return new JsonResponse(['error' => 'is_available is required'], 422);
        }

        try {
            $result = $this->setAgentAvailabilityUseCase->execute(
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

        $agent = $this->agentRepo->findById($id);
        if ($agent === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        try {
            $this->clearAgentAvailabilityUseCase->execute($id);

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

        $agent = $this->agentRepo->findById($id);
        if ($agent === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $localTz = new \DateTimeZone($agent->getTimezone());
        $localNow = $nowUtc->setTimezone($localTz);

        $isAvailable = $this->agentAvailabilityService->isDisponible($agent, $nowUtc);
        $currentLoad = $this->ticketRepo->countActivosDeAgente($id);

        // Compute is_in_shift by checking schedule only (no override, no capacity)
        $isInShift = $this->isAgenteEnTurno($agent, $localNow);

        // Compute override info
        $overrideActive = false;
        $overrideAvailable = null;
        $overrideExpiresAt = null;

        if ($agent->getIsAvailableOverride() !== null) {
            if ($agent->getOverrideUntil() === null || $agent->getOverrideUntil() >= $nowUtc) {
                $overrideActive = true;
                $overrideAvailable = $agent->getIsAvailableOverride();
                $overrideExpiresAt = $agent->getOverrideUntil()?->format('Y-m-d\TH:i:s\Z');
            }
        }

        return new JsonResponse([
            'agent_id'     => $id,
            'is_available' => $isAvailable,
            'is_in_shift'  => $isInShift,
            'timezone'     => $agent->getTimezone(),
            'current_load' => $currentLoad,
            'max_capacity' => $agent->getMaxActiveTickets(),
            'override'     => [
                'active'       => $overrideActive,
                'is_available' => $overrideAvailable,
                'expires_at'   => $overrideExpiresAt,
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

        $agent = $this->agentRepo->findById($id);
        if ($agent === null) {
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
            $result = $this->createAgentShiftUseCase->execute(
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

        $agent = $this->agentRepo->findById($id);
        if ($agent === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $shift = $this->agentShiftRepo->find($shiftId);
        if ($shift === null) {
            return new JsonResponse(['error' => 'Shift not found'], 404);
        }

        $shiftAgent = $shift->getAgente();
        if ($shiftAgent === null || $shiftAgent->getId() !== $agent->getId()) {
            return new JsonResponse(['error' => 'Shift does not belong to this agent'], 404);
        }

        try {
            $this->deleteAgentShiftUseCase->execute($id, $shiftId);

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
    private function isPermitidoParaAgente(int $agentId): bool
    {
        $scopeId = $this->ownershipScope->getAgentId();

        // null scope means SUPERVISOR/ADMIN (no scope restriction)
        if (null === $scopeId) {
            return true;
        }

        // AGENT: only their own agent
        return $scopeId === $agentId;
    }

    /**
     * Check if an agent has a shift at the given local time.
     *
     * Reuses the same cross-midnight logic as DisponibilidadDeAgenteService
     * but checks schedule only — no override, no capacity.
     */
    private function isAgenteEnTurno(\App\Agente\Agent $agent, \DateTimeImmutable $localDateTime): bool
    {
        $dow = (int) $localDateTime->format('N');
        $time = $localDateTime->format('H:i:s');

        foreach ($agent->getShifts() as $shift) {
            $startTime = $shift->getHoraDeInicio()->format('H:i:s');
            $endTime = $shift->getHoraDeFin()->format('H:i:s');

            if ($endTime > $startTime) {
                // Normal shift (same day, half-open [start, end))
                if ($shift->getDiaDeLaSemana() === $dow && $time >= $startTime && $time < $endTime) {
                    return true;
                }
            } else {
                // Cross-midnight shift (end < start, e.g. 22:00-06:00)
                $nextDow = $shift->getDiaDeLaSemana() === 7 ? 1 : $shift->getDiaDeLaSemana() + 1;

                if ($dow === $shift->getDiaDeLaSemana() && $time >= $startTime) {
                    return true; // First night
                }
                if ($dow === $nextDow && $time < $endTime) {
                    return true; // Next morning
                }
            }
        }

        return false;
    }
}
