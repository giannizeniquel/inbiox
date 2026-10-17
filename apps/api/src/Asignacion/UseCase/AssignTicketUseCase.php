<?php

declare(strict_types=1);

namespace App\Asignacion\UseCase;

use App\Disponibilidad\AgentAvailabilityService;
use App\Asignacion\AssignmentService;
use App\Asignacion\AssignmentType;
use App\Compartido\EventCollector;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketUpdatedEvent;
use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Ticket\EstadoDeTicket;
use App\Disponibilidad\AgentNotActiveException;
use App\Agente\Repository\AgentRepository;
use App\Asignacion\Repository\AssignmentRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/**
 * Manual ticket assignment by a supervisor.
 *
 * Routes through AssignmentService for the audit trail — the ONLY path
 * that modifies ticket.assigned_agent_id.
 *
 * Assignment types detected:
 *   - MANUAL_ASSIGN: ticket had no owner → initial assignment
 *   - REASSIGN:      ticket already had an owner → transfer
 */
class AssignTicketUseCase
{
    /** Track the last assignment type for outbox payload enrichment. */
    private ?string $lastAssignmentType = null;

    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private AssignmentRepository $assignmentRepo,
        private AgentRepository $agentRepo,
        private EventCollector $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private AssignmentService $assignmentService,
        private AgentAvailabilityService $agentAvailabilityService,
    ) {}

    /**
     * Assign a ticket to an agent (manual assignment).
     *
     * @param int $ticketId
     * @param int $agentId
     * @param string $assignedBy
     * @param string|null $notes
     * @return array
     * @throws \InvalidArgumentException if ticket or agent not found
     */
    public function assignTicket(int $ticketId, int $agentId, string $assignedBy, ?string $notes = null): array
    {
        // Validate input
        if ($agentId <= 0) {
            throw new \InvalidArgumentException('Agent ID is required');
        }

        if ($assignedBy === '') {
            throw new \InvalidArgumentException('Assigned by is required');
        }

        $this->lastAssignmentType = null;

        $result = $this->em->getConnection()->transactional(function () use ($ticketId, $agentId, $assignedBy, $notes) {
            // Find the agent
            $agent = $this->agentRepo->findById($agentId);
            if (!$agent) {
                throw new \InvalidArgumentException('Agent not found');
            }

            // Find the ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
            }

            // Lock ticket to prevent concurrent modifications
            $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);

            // Assign-time Activo guard: HARD BLOCK for every role, no bypass.
            // Capacity is never checked here — only account-active + override/shift.
            $isActiveNow = $this->agentAvailabilityService->isActiveNow(
                $agent,
                $agent->getShifts()->toArray(),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            if (!$isActiveNow) {
                throw AgentNotActiveException::forAgent($agentId, $agent->getName());
            }

            // Detect assignment type from current ownership
            $currentOwnerId = $ticket->getAssignedAgentId();
            $previousAgent = null;

            if ($currentOwnerId !== null && $currentOwnerId !== $agentId) {
                // REASSIGN — ticket has a different owner
                $previousAgent = $this->agentRepo->findById($currentOwnerId);
            }

            $type = $currentOwnerId !== null ? AssignmentType::REASSIGN : AssignmentType::MANUAL_ASSIGN;
            $this->lastAssignmentType = $type->value;

            // Route through AssignmentService (creates audit row, updates ticket, emits events)
            $assignment = $this->assignmentService->recordOwnershipChange(
                ticket: $ticket,
                agent: $agent,
                previousAgent: $previousAgent,
                type: $type,
                assignedBy: $assignedBy,
                notes: $notes,
            );

            $this->em->flush();

            return [
                'assignment_id' => $assignment->getId(),
                'ticket_id' => $ticket->getId(),
                'agent_id' => $assignment->getAgentId(),
                'agent_name' => $agent->getName(),
                'assigned_by' => $assignment->getAssignedBy(),
                'assigned_at' => $assignment->getAssignedAt()->format('Y-m-d H:i:s'),
                'ticket_status' => $ticket->getStatus(),
                'notes' => $assignment->getNotes(),
                'status' => 'ok',
            ];
        });

        // After commit, persist collected events to outbox
        $this->persistEventsToOutbox();

        return $result;
    }

    /**
     * Persist collected domain events to outbox table.
     */
    private function persistEventsToOutbox(): void
    {
        $events = $this->eventCollector->getEvents();
        if (empty($events)) {
            return;
        }

        $outboxEvents = [];

        foreach ($events as $event) {
            $payload = [
                'eventId' => $event->eventId->toRfc4122(),
                'type' => $event->type,
                'ticketId' => $event->ticketId,
                'occurredAt' => $event->occurredAt->format(\DateTimeInterface::RFC3339),
                'seq' => $event->seq,
                'version' => $event->version,
            ];

            // Add event-specific fields
            if ($event instanceof TicketUpdatedEvent) {
                $payload['status'] = $event->status?->value;
                $payload['ownerAgentId'] = $event->ownerAgentId;
                $payload['assignee_id'] = $event->ownerAgentId;
                $payload['assignment_type'] = $this->lastAssignmentType;
            }

            $outboxEvent = new OutboxEvent(
                aggregateType: 'ticket',
                aggregateId: $event->ticketId,
                topic: 'tickets',
                eventType: $event->type,
                payload: $payload,
            );
            $outboxEvents[] = $outboxEvent;
        }

        $this->outboxWriter->write(...$outboxEvents);
        $this->eventCollector->clear();
    }
}
