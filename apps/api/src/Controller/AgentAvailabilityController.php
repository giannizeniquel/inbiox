<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\DTO\CreateAgentShiftRequest;
use App\Application\DTO\SetAgentAvailabilityRequest;
use App\Application\Service\AgentAvailabilityService;
use App\Application\UseCase\ClearAgentAvailabilityUseCase;
use App\Application\UseCase\CreateAgentShiftUseCase;
use App\Application\UseCase\DeleteAgentShiftUseCase;
use App\Application\UseCase\SetAgentAvailabilityUseCase;
use App\Domain\Agent\AgentShiftRepository;
use App\Repository\AgentRepository;
use App\Repository\TicketRepository;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Agent availability and shift management endpoints.
 *
 * Thin controllers — all business logic is delegated to UseCases or Services.
 * No direct entityManager->persist(), no direct Mercure publishing.
 */
class AgentAvailabilityController
{
    public function __construct(
        private SetAgentAvailabilityUseCase $setAgentAvailabilityUseCase,
        private ClearAgentAvailabilityUseCase $clearAgentAvailabilityUseCase,
        private CreateAgentShiftUseCase $createAgentShiftUseCase,
        private DeleteAgentShiftUseCase $deleteAgentShiftUseCase,
        private AgentAvailabilityService $agentAvailabilityService,
        private AgentRepository $agentRepo,
        private AgentShiftRepository $agentShiftRepo,
        private TicketRepository $ticketRepo,
        private LoggerInterface $logger,
    ) {}

    /**
     * POST /agents/{id}/availability
     *
     * Set an agent's availability override with optional TTL.
     * Returns 200 with the updated availability state.
     */
    #[Route('/agents/{id}/availability', methods: ['POST'])]
    public function setAvailability(
        int $id,
        #[MapRequestPayload] SetAgentAvailabilityRequest $request,
    ): JsonResponse {
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
        } catch (\Exception $e) {
            $referenceId = \bin2hex(\random_bytes(8));
            $this->logger->error('Error setting agent availability: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * DELETE /agents/{id}/availability
     *
     * Clear an agent's availability override (reset to NULL).
     * Returns 204 on success.
     */
    #[Route('/agents/{id}/availability', methods: ['DELETE'])]
    public function clearAvailability(int $id): JsonResponse
    {
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
        } catch (\Exception $e) {
            $referenceId = \bin2hex(\random_bytes(8));
            $this->logger->error('Error clearing agent availability: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * GET /agents/{id}/availability
     *
     * Read the full availability state for an agent.
     * Reuses AgentAvailabilityService for determination. Read-only.
     */
    #[Route('/agents/{id}/availability', methods: ['GET'])]
    public function getAvailability(int $id): JsonResponse
    {
        $agent = $this->agentRepo->findById($id);
        if ($agent === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        try {
            $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $localTz = new \DateTimeZone($agent->getTimezone());
            $localNow = $nowUtc->setTimezone($localTz);

            $isAvailable = $this->agentAvailabilityService->isAvailable($agent, $nowUtc);
            $currentLoad = $this->ticketRepo->countActiveForAgent($id);

            // Compute is_in_shift by checking schedule only (no override, no capacity)
            $isInShift = $this->isAgentInShift($agent, $localNow);

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
        } catch (\Exception $e) {
            $referenceId = \bin2hex(\random_bytes(8));
            $this->logger->error('Error getting agent availability: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * POST /agents/{id}/shifts
     *
     * Create a weekly shift for an agent.
     * Returns 201 with the created shift data.
     */
    #[Route('/agents/{id}/shifts', methods: ['POST'])]
    public function createShift(
        int $id,
        #[MapRequestPayload] CreateAgentShiftRequest $request,
    ): JsonResponse {
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
        } catch (\Exception $e) {
            $referenceId = \bin2hex(\random_bytes(8));
            $this->logger->error('Error creating agent shift: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * DELETE /agents/{id}/shifts/{shiftId}
     *
     * Delete an agent's shift.
     * Returns 204 on success.
     */
    #[Route('/agents/{id}/shifts/{shiftId}', methods: ['DELETE'])]
    public function deleteShift(int $id, int $shiftId): JsonResponse
    {
        $agent = $this->agentRepo->findById($id);
        if ($agent === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $shift = $this->agentShiftRepo->find($shiftId);
        if ($shift === null) {
            return new JsonResponse(['error' => 'Shift not found'], 404);
        }

        $shiftAgent = $shift->getAgent();
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
        } catch (\Exception $e) {
            $referenceId = \bin2hex(\random_bytes(8));
            $this->logger->error('Error deleting agent shift: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Check if an agent has a shift at the given local time.
     *
     * Reuses the same cross-midnight logic as AgentAvailabilityService
     * but checks schedule only — no override, no capacity.
     */
    private function isAgentInShift(\App\Domain\Agent\Agent $agent, \DateTimeImmutable $localDateTime): bool
    {
        $dow = (int) $localDateTime->format('N');
        $time = $localDateTime->format('H:i:s');

        foreach ($agent->getShifts() as $shift) {
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
