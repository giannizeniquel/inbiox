<?php

namespace App\Application\UseCase;

use App\Domain\Assignment\Assignment;
use App\Domain\Event\EventCollector;
use App\Domain\Event\InboxUpdatedEvent;
use App\Domain\Event\TicketUpdatedEvent;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Repository\AssignmentRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Uid\Ulid;

class AssignTicketUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private AssignmentRepository $assignmentRepo,
        private EventCollector $eventCollector,
    ) {}

    /**
     * Assign a ticket to an agent
     *
     * @param int $ticketId
     * @param string $agentId
     * @param string $assignedBy
     * @param string|null $notes
     * @return array
     * @throws \InvalidArgumentException if ticket not found
     */
    public function assignTicket(int $ticketId, string $agentId, string $assignedBy, ?string $notes = null): array
    {
        // Validate input
        if (empty($agentId)) {
            throw new \InvalidArgumentException('Agent ID is required');
        }

        if (empty($assignedBy)) {
            throw new \InvalidArgumentException('Assigned by is required');
        }

        // Store old agent ID for InboxUpdatedEvent (to be used after commit)
        $oldAgentId = null;

        $result = $this->em->getConnection()->transactional(function() use ($ticketId, $agentId, $assignedBy, $notes, &$oldAgentId) {
            // Find the ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
            }

            // Lock ticket to prevent seq duplicates under concurrency
            $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);

            // Get current assignment to track old agent
            $currentAssignment = $this->assignmentRepo->findCurrentByTicket($ticketId);
            if ($currentAssignment) {
                $oldAgentId = $currentAssignment->getAgentId();
            }

            // Create the assignment record
            $assignment = new Assignment(
                $ticket,
                $agentId,
                $assignedBy,
                $notes
            );

            $this->em->persist($assignment);

            // Update ticket status to ACTIVE if it was NEW
            $statusChanged = false;
            if ($ticket->getStatus() === TicketStatus::NEW->value) {
                $ticket->setStatus(TicketStatus::ACTIVE->value);
                $statusChanged = true;
            }

            $ticket->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();

            // Collect TicketUpdatedEvent (owner changed, status may have changed)
            $this->eventCollector->collect(new TicketUpdatedEvent(
                eventId: new Ulid(),
                ticketId: $ticket->getId(),
                occurredAt: new \DateTimeImmutable(),
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
                status: $statusChanged ? TicketStatus::ACTIVE : null,
                ownerAgentId: (int) $agentId
            ));

            // Collect InboxUpdatedEvent for old agent (if different)
            if ($oldAgentId !== null && $oldAgentId !== $agentId) {
                $this->eventCollector->collect(new InboxUpdatedEvent(
                    eventId: new Ulid(),
                    ticketId: $ticket->getId(),
                    occurredAt: new \DateTimeImmutable(),
                    seq: $ticket->incrementLastEventSeq(),
                    version: 1
                ));
            }

            // Collect InboxUpdatedEvent for new agent
            $this->eventCollector->collect(new InboxUpdatedEvent(
                eventId: new Ulid(),
                ticketId: $ticket->getId(),
                occurredAt: new \DateTimeImmutable(),
                seq: $ticket->incrementLastEventSeq(),
                version: 1
            ));

            return [
                'assignment_id' => $assignment->getId(),
                'ticket_id' => $ticket->getId(),
                'agent_id' => $assignment->getAgentId(),
                'assigned_by' => $assignment->getAssignedBy(),
                'assigned_at' => $assignment->getAssignedAt()->format('Y-m-d H:i:s'),
                'ticket_status' => $ticket->getStatus(),
                'notes' => $assignment->getNotes(),
                'status' => 'ok'
            ];
        });

        // After commit, persist collected events to outbox
        $this->persistEventsToOutbox();

        return $result;
    }

    /**
     * Persist collected domain events to outbox table
     */
    private function persistEventsToOutbox(): void
    {
        $events = $this->eventCollector->getEvents();
        if (empty($events)) {
            return;
        }

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
            }

            $outboxEvent = new OutboxEvent(
                aggregateType: 'ticket',
                aggregateId: $event->ticketId,
                topic: 'ticket/' . $event->ticketId,
                eventType: $event->type,
                payload: $payload
            );
            $this->em->persist($outboxEvent);
        }

        $this->em->flush();
        $this->eventCollector->clear();
    }
}
