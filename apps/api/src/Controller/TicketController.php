<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\AssignTicketUseCase;
use App\Repository\AssignmentRepository;
use App\Repository\TicketRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private LoggerInterface $logger
    ) {}

    #[Route('/tickets/{id}/assign', methods: ['POST'])]
    public function assign(
        int $id,
        #[MapRequestPayload] AssignTicketRequest $request
    ): JsonResponse {
        try {
            $result = $this->assignTicketUseCase->assignTicket(
                $id,
                $request->agentId,
                $request->assignedBy,
                $request->notes
            );
            return new JsonResponse($result);
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
