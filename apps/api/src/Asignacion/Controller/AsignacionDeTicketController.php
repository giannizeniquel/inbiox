<?php

declare(strict_types=1);

namespace App\Asignacion\Controller;

use App\Asignacion\UseCase\AsignarTicketUseCase;
use App\Asignacion\UseCase\SetEquipoDeTicketUseCase;
use App\Disponibilidad\AgenteInactivoException;
use App\Compartido\StateConflictException;
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
        private AsignarTicketUseCase $assignTicketUseCase,
        private TicketRepository $ticketRepo,
        private AsignacionRepository $assignmentRepo,
        private Security $security,
        private SetEquipoDeTicketUseCase $setTicketTeamUseCase,
    ) {}

    #[Route('/api/tickets/{id}/assign', name: 'app_ticket_assign', methods: ['POST'])]
    public function asignar(
        int $id,
        #[MapRequestPayload] AsignarTicketRequest $request
    ): JsonResponse {
        try {
            // Load ticket for voter check
            $ticket = $this->ticketRepo->findById($id);
            if (!$ticket) {
                return new JsonResponse(['error' => 'Ticket not found'], 404);
            }

            // Check ASSIGN permission via voter
            if (!$this->security->isGranted('ASSIGN', $ticket)) {
                return new JsonResponse(['error' => 'Forbidden'], 403);
            }

            $result = $this->assignTicketUseCase->asignar(
                $id,
                $request->agentId,
                $request->assignedBy,
                $request->notes
            );
            return new JsonResponse($result);
        } catch (AgenteInactivoException $e) {
            // Subclass-first: must be caught BEFORE the generic StateConflictException
            // arm below so the frontend gets the machine-readable discriminator.
            return new JsonResponse(['type' => 'AGENT_NOT_ACTIVE', 'error' => $e->getMessage()], 409);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Set or clear a ticket's team (Tier 2 #7 — Team/Area Entity, Slice 1).
     *
     * Any authenticated agent may call this — no Supervisor/Admin restriction and no ownership voter
     * (design.md D5). `teamId: null` clears the assignment. Enforces the
     * cross-workspace invariant: the team's workspace phone_number_id must
     * match the ticket's phone_number_id, otherwise 400 and team_id is left
     * unchanged.
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

            $teamId = $data['teamId'];
            $this->setTicketTeamUseCase->execute($ticket, $teamId);

            return new JsonResponse(['ticket_id' => $ticket->getId(), 'team_id' => $teamId]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/tickets/{id}/assignment', name: 'app_ticket_assignment', methods: ['GET'])]
    public function getAsignacion(int $id): JsonResponse
    {
        // Find the ticket first
        $ticket = $this->ticketRepo->findById($id);
        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        // Get assignment history
        $assignments = $this->assignmentRepo->findByTicket($ticket);

        $data = [
            'ticket_id' => $ticket->getId(),
            'assignments' => array_map(function ($assignment) {
                return [
                    'id' => $assignment->getId(),
                    'agent_id' => $assignment->getAgenteId(),
                    'assigned_by' => $assignment->getAsignadoPor(),
                    'assigned_at' => $assignment->getAsignadoAt()->format('Y-m-d H:i:s'),
                    'notes' => $assignment->getNotas(),
                ];
            }, $assignments),
            'current_assignment' => null,
        ];

        // Get the most recent assignment as current
        if (!empty($assignments)) {
            $current = $assignments[0]; // Already ordered by DESC
            $data['current_assignment'] = [
                'agent_id' => $current->getAgenteId(),
                'assigned_by' => $current->getAsignadoPor(),
                'assigned_at' => $current->getAsignadoAt()->format('Y-m-d H:i:s'),
            ];
        }

        return new JsonResponse($data);
    }
}
