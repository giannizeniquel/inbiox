<?php

declare(strict_types=1);

namespace App\Asignacion\Controller;

use App\Asignacion\UseCase\AsignarTicketUseCase;
use App\Asignacion\UseCase\SetEquipoDeTicketUseCase;
use App\Disponibilidad\AgenteInactivoException;
use App\Compartido\ConflictoDeEstadoException;
use App\Asignacion\Repository\AsignacionRepository;
use App\Ticket\Repository\TicketRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class AsignacionDeTicketController
{
    public function __construct(
        private AsignarTicketUseCase $asignarTicketUseCase,
        private TicketRepository $ticketRepo,
        private AsignacionRepository $asignacionRepo,
        private Security $security,
        private SetEquipoDeTicketUseCase $setEquipoDeTicketUseCase,
    ) {}

    #[Route('/api/tickets/{id}/assign', name: 'app_ticket_assign', methods: ['POST'])]
    public function asignar(
        int $id,
        #[MapRequestPayload] AsignarTicketRequest $request
    ): JsonResponse {
        try {
            // Carga el ticket para la verificación del voter
            $ticket = $this->ticketRepo->findById($id);
            if (!$ticket) {
                return new JsonResponse(['error' => 'Ticket not found'], 404);
            }

            // Verifica el permiso ASSIGN mediante el voter
            if (!$this->security->isGranted('ASSIGN', $ticket)) {
                return new JsonResponse(['error' => 'Forbidden'], 403);
            }

            $result = $this->asignarTicketUseCase->asignar(
                $id,
                $request->agentId,
                $request->assignedBy,
                $request->notes
            );
            return new JsonResponse($result);
        } catch (AgenteInactivoException $e) {
            // Primero la subclase: debe capturarse ANTES del bloque genérico de ConflictoDeEstadoException
            // de abajo para que el frontend reciba el discriminador legible por máquina.
            return new JsonResponse(['type' => 'AGENT_NOT_ACTIVE', 'error' => $e->getMessage()], 409);
        } catch (ConflictoDeEstadoException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Asigna o quita el equipo de un ticket (Tier 2 #7 — Team/Area Entity, Slice 1).
     *
     * Cualquier agente autenticado puede llamarlo — sin restricción de Supervisor/Admin y sin voter de responsable
     * (design.md D5). `teamId: null` quita la asignación. Aplica la
     * invariante entre espacios de trabajo: el phone_number_id del espacio de trabajo del equipo debe
     * coincidir con el phone_number_id del ticket; si no, responde 400 y team_id queda
     * sin cambios.
     */
    #[Route('/api/tickets/{id}/team', name: 'app_ticket_set_team', methods: ['PUT'])]
    public function setEquipo(int $id, Request $request): JsonResponse
    {
        try {
            $ticket = $this->ticketRepo->findById($id);
            if (!$ticket) {
                return new JsonResponse(['error' => 'Ticket not found'], 404);
            }

            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE || !\is_array($data) || !\array_key_exists('teamId', $data)) {
                return new JsonResponse(['error' => 'teamId is required (integer or null)'], 400);
            }

            $equipoId = $data['teamId'];
            $this->setEquipoDeTicketUseCase->execute($ticket, $equipoId);

            return new JsonResponse(['ticket_id' => $ticket->getId(), 'team_id' => $equipoId]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/tickets/{id}/assignment', name: 'app_ticket_assignment', methods: ['GET'])]
    public function getAsignacion(int $id): JsonResponse
    {
        // Primero busca el ticket
        $ticket = $this->ticketRepo->findById($id);
        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        // Obtiene el historial de asignaciones
        $asignaciones = $this->asignacionRepo->findByTicket($ticket);

        $data = [
            'ticket_id' => $ticket->getId(),
            'assignments' => array_map(function ($asignacion) {
                return [
                    'id' => $asignacion->getId(),
                    'agent_id' => $asignacion->getAgenteId(),
                    'assigned_by' => $asignacion->getAsignadoPor(),
                    'assigned_at' => $asignacion->getAsignadoAt()->format('Y-m-d H:i:s'),
                    'notes' => $asignacion->getNotas(),
                ];
            }, $asignaciones),
            'current_assignment' => null,
        ];

        // Toma la asignación más reciente como la actual
        if (!empty($asignaciones)) {
            $actual = $asignaciones[0]; // Ya viene ordenado DESC
            $data['current_assignment'] = [
                'agent_id' => $actual->getAgenteId(),
                'assigned_by' => $actual->getAsignadoPor(),
                'assigned_at' => $actual->getAsignadoAt()->format('Y-m-d H:i:s'),
            ];
        }

        return new JsonResponse($data);
    }
}
