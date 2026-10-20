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
 * Endpoints de disponibilidad y gestión de turnos de agentes.
 *
 * Controladores delgados: toda la lógica de negocio se delega a UseCases o Services.
 * Sin entityManager->persist() directo ni publicación directa en Mercure.
 *
 * Autorización:
 * - Todos los métodos requieren ROLE_AGENT
 * - Un agente solo puede ver/gestionar su propia disponibilidad
 * - Admin/Supervisor pueden ver/gestionar cualquier agente
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
        private AlcanceDeResponsabilidad $alcanceDeResponsabilidad,
    ) {}

    /**
     * POST /agents/{id}/availability
     *
     * Define el override de disponibilidad de un agente con TTL opcional.
     * Devuelve 200 con el estado de disponibilidad actualizado.
     */
    #[Route('/api/agents/{id}/availability', name: 'app_agent_set_availability', methods: ['POST'])]
    public function setDisponibilidadManual(
        int $id,
        #[MapRequestPayload] SetDisponibilidadManualRequest $request,
    ): JsonResponse {
        // Verificación de pertenencia: un AGENT solo puede modificar su propia disponibilidad
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
     * Quita el override de disponibilidad de un agente (lo vuelve a NULL).
     * Devuelve 204 si tiene éxito.
     */
    #[Route('/api/agents/{id}/availability', name: 'app_agent_clear_availability', methods: ['DELETE'])]
    public function quitarDisponibilidadManual(int $id): JsonResponse
    {
        // Verificación de pertenencia: un AGENT solo puede modificar su propia disponibilidad
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
     * Lee el estado completo de disponibilidad de un agente.
     * Reutiliza DisponibilidadDeAgenteService para determinarlo. Solo lectura.
     */
    #[Route('/api/agents/{id}/availability', name: 'app_agent_get_availability', methods: ['GET'])]
    public function getDisponibilidad(int $id): JsonResponse
    {
        // Verificación de pertenencia: un AGENT solo puede ver su propia disponibilidad
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

        // Calcula is_in_shift verificando solo el horario (sin override ni capacidad)
        $isEnTurno = $this->isAgenteEnTurno($agente, $localNow);

        // Calcula la información del override
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
     * Crea un turno semanal para un agente.
     * Devuelve 201 con los datos del turno creado.
     */
    #[Route('/api/agents/{id}/shifts', name: 'app_agent_create_shift', methods: ['POST'])]
    public function createTurno(
        int $id,
        #[MapRequestPayload] CreateTurnoRequest $request,
    ): JsonResponse {
        // Verificación de pertenencia: un AGENT solo puede modificar sus propios turnos
        if (false === $this->isPermitidoParaAgente($id)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $agente = $this->agentRepo->findById($id);
        if ($agente === null) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        // La validación del DTO ya garantiza dayOfWeek 1-7 y el formato HH:MM.
        // Validación adicional: start != end (el DTO no puede validar campos cruzados).
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
     * Elimina un turno de un agente.
     * Devuelve 204 si tiene éxito.
     */
    #[Route('/api/agents/{id}/shifts/{shiftId}', name: 'app_agent_delete_shift', methods: ['DELETE'])]
    public function deleteTurno(int $id, int $shiftId): JsonResponse
    {
        // Verificación de pertenencia: un AGENT solo puede modificar sus propios turnos
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
     * Verifica si el usuario actual puede actuar sobre el agente dado.
     *
     * ADMIN/SUPERVISOR pueden actuar sobre cualquier agente.
     * AGENT solo puede actuar sobre su propio agente.
     */
    private function isPermitidoParaAgente(int $agenteId): bool
    {
        $scopeId = $this->alcanceDeResponsabilidad->getAgenteId();

        // un scope null significa SUPERVISOR/ADMIN (sin restricción de alcance)
        if (null === $scopeId) {
            return true;
        }

        // AGENT: solo su propio agente
        return $scopeId === $agenteId;
    }

    /**
     * Verifica si un agente tiene un turno en la hora local dada.
     *
     * Reutiliza la misma lógica de cruce de medianoche que DisponibilidadDeAgenteService
     * pero verifica solo el horario, sin override ni capacidad.
     */
    private function isAgenteEnTurno(\App\Agente\Agente $agente, \DateTimeImmutable $localDateTime): bool
    {
        $dow = (int) $localDateTime->format('N');
        $time = $localDateTime->format('H:i:s');

        foreach ($agente->getTurnos() as $turno) {
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
                    return true; // Primera noche
                }
                if ($dow === $nextDow && $time < $horaDeFin) {
                    return true; // Mañana siguiente
                }
            }
        }

        return false;
    }
}
