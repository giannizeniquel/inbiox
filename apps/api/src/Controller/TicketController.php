<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\AssignTicketUseCase;
use App\Domain\Team\Team;
use App\Exception\AgentNotActiveException;
use App\Exception\StateConflictException;
use App\Repository\AssignmentRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class TicketController
{
    public function __construct(
        private AssignTicketUseCase $assignTicketUseCase,
        private TicketRepository $ticketRepo,
        private AssignmentRepository $assignmentRepo,
        private LoggerInterface $logger,
        private Security $security,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Route('/tickets/{id}/assign', methods: ['POST'])]
    public function assign(
        int $id,
        #[MapRequestPayload] AssignTicketRequest $request
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

            $result = $this->assignTicketUseCase->assignTicket(
                $id,
                $request->agentId,
                $request->assignedBy,
                $request->notes
            );
            return new JsonResponse($result);
        } catch (AgentNotActiveException $e) {
            // Subclass-first: must be caught BEFORE the generic StateConflictException
            // arm below so the frontend gets the machine-readable discriminator.
            return new JsonResponse(['type' => 'AGENT_NOT_ACTIVE', 'error' => $e->getMessage()], 409);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error assigning ticket: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
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
    #[Route('/tickets/{id}/team', methods: ['PUT'])]
    public function setTeam(int $id, Request $request): JsonResponse
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

            if (null === $teamId) {
                $ticket->setTeamId(null);
                $this->entityManager->flush();
                return new JsonResponse(['ticket_id' => $ticket->getId(), 'team_id' => null]);
            }

            if (!\is_int($teamId)) {
                return new JsonResponse(['error' => 'teamId must be an integer or null'], 400);
            }

            $team = $this->entityManager->getRepository(Team::class)->find($teamId);
            if (null === $team) {
                return new JsonResponse(['error' => 'Team not found'], 400);
            }

            if ($team->getWorkspace()->getPhoneNumberId() !== $ticket->getPhoneNumberId()) {
                return new JsonResponse(['error' => 'Team does not belong to the same workspace as the ticket'], 400);
            }

            $ticket->setTeamId($teamId);
            $this->entityManager->flush();

            return new JsonResponse(['ticket_id' => $ticket->getId(), 'team_id' => $teamId]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error setting ticket team: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/tickets/{id}/assignment', methods: ['GET'])]
    public function getAssignment(int $id): JsonResponse
    {
        try {
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
                        'agent_id' => $assignment->getAgentId(),
                        'assigned_by' => $assignment->getAssignedBy(),
                        'assigned_at' => $assignment->getAssignedAt()->format('Y-m-d H:i:s'),
                        'notes' => $assignment->getNotes(),
                    ];
                }, $assignments),
                'current_assignment' => null,
            ];

            // Get the most recent assignment as current
            if (!empty($assignments)) {
                $current = $assignments[0]; // Already ordered by DESC
                $data['current_assignment'] = [
                    'agent_id' => $current->getAgentId(),
                    'assigned_by' => $current->getAssignedBy(),
                    'assigned_at' => $current->getAssignedAt()->format('Y-m-d H:i:s'),
                ];
            }

            return new JsonResponse($data);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching ticket assignment: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }
}
