<?php

declare(strict_types=1);

namespace App\Asignacion\UseCase;

use App\Disponibilidad\AgentAvailabilityService;
use App\Asignacion\AssignmentService;
use App\Agente\Agent;
use App\Asignacion\AssignmentType;
use App\Compartido\EventCollector;
use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Ticket\TicketStatus;
use App\Agente\Repository\AgentRepository;
use App\Asignacion\Repository\AssignmentRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deterministic auto-assignment of tickets to the least-loaded eligible agent.
 *
 * Pipeline (single transaction):
 *   1. SELECT ticket FOR UPDATE (idempotent)
 *   2. listAvailableAgents() via AgentAvailabilityService (SOLE eligibility check)
 *   3. SELECT agents FOR UPDATE WHERE id IN (...)
 *   4. Re-filter capacity inside lock (COUNT may have changed)
 *   5. Tiebreaker: lowest active count → fewest AUTO_ASSIGN today → lowest id
 *   6. AssignmentService.recordOwnershipChange()
 *   7. Collect outbox events for PR4 Mercure
 *
 * The old hardcoded "WHERE active = 1 AND is_available = 1" filter is REMOVED.
 * AgentAvailabilityService is the SOLE eligibility check.
 */
final class AutoAssignTicketUseCase
{
    /** Track the last assignment type for outbox payload enrichment. */
    private ?string $lastAssignmentType = null;

    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private AssignmentRepository $assignmentRepo,
        private AgentAvailabilityService $availabilityService,
        private AssignmentService $assignmentService,
        private EventCollector $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private AgentRepository $agentRepo,
    ) {}

    /**
     * Execute auto-assignment in its own transaction.
     *
     * @param int $ticketId
     * @return array{ticket_id: int, assigned: bool, agent_id: ?int, reason: string}
     */
    public function execute(int $ticketId): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($ticketId) {
            return $this->executeInternal($ticketId);
        });

        // After commit, persist collected events to outbox (for PR4)
        $this->persistEventsToOutbox();

        return $result;
    }

    /**
     * Execute auto-assignment WITHOUT starting a new transaction.
     * Use this when already inside a transaction (e.g., the inbound-message listener).
     *
     * @param int $ticketId
     * @return array{ticket_id: int, assigned: bool, agent_id: ?int, reason: string}
     */
    public function executeInTransaction(int $ticketId): array
    {
        return $this->executeInternal($ticketId);
    }

    /**
     * Internal execution logic — must be called inside a transaction.
     */
    private function executeInternal(int $ticketId): array
    {
        // 1. Lock the ticket row FOR UPDATE
        $ticketRow = $this->ticketRepo->findRowByIdForUpdate($ticketId);

        if (!$ticketRow) {
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => null,
                'reason' => 'ticket_not_found',
            ];
        }

        // 2. If already assigned, return idempotent (no new Assignment row)
        if ($ticketRow['assigned_agent_id'] !== null) {
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => (int) $ticketRow['assigned_agent_id'],
                'reason' => 'already_assigned',
            ];
        }

        // 3. Get available agents via AgentAvailabilityService (SOLE eligibility check)
        //    This uses the full cascade: active → override → schedule → capacity.
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $availableAgents = $this->availabilityService->listAvailableAgents($nowUtc);

        if (empty($availableAgents)) {
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => null,
                'reason' => 'no_agents_available',
            ];
        }

        // 4. Lock candidate agent rows FOR UPDATE
        $agentIds = array_map(fn (Agent $a): int => (int) $a->getId(), $availableAgents);
        $this->agentRepo->lockRowsForUpdate($agentIds);

        // 5. Re-filter capacity inside the lock + gather tiebreaker data
        $todayStart = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTime(0, 0, 0);
        $eligible = [];

        foreach ($availableAgents as $agent) {
            $activeCount = $this->ticketRepo->countActiveForAgent((int) $agent->getId());
            if ($activeCount >= $agent->getMaxActiveTickets()) {
                continue; // at capacity — skip
            }

            $todayCount = $this->assignmentRepo->countAutoAssignedToday(
                (int) $agent->getId(),
                $todayStart,
            );

            $eligible[] = [
                'agent' => $agent,
                'activeCount' => $activeCount,
                'todayCount' => $todayCount,
            ];
        }

        if (empty($eligible)) {
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => null,
                'reason' => 'all_agents_at_capacity',
            ];
        }

        // 6. DETERMINISTIC tiebreaker (no shuffle, no rand — same data = same result)
        usort($eligible, function (array $a, array $b): int {
            // First: lowest active ticket count
            if ($a['activeCount'] !== $b['activeCount']) {
                return $a['activeCount'] <=> $b['activeCount'];
            }
            // Second: fewest AUTO_ASSIGN today
            if ($a['todayCount'] !== $b['todayCount']) {
                return $a['todayCount'] <=> $b['todayCount'];
            }
            // Third: lowest agent.id
            return $a['agent']->getId() <=> $b['agent']->getId();
        });

        $selectedAgent = $eligible[0]['agent'];

        // 7. Load the Ticket entity for AssignmentService
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            // Should not happen — we already locked the row above
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => null,
                'reason' => 'ticket_not_found',
            ];
        }

        // 8. Record ownership change via AssignmentService (single point of change)
        //    This creates the Assignment audit row, updates ticket.assigned_agent_id,
        //    and emits domain events.
        $this->lastAssignmentType = AssignmentType::AUTO_ASSIGN->value;
        $this->assignmentService->recordOwnershipChange(
            ticket: $ticket,
            agent: $selectedAgent,
            previousAgent: null,
            type: AssignmentType::AUTO_ASSIGN,
            assignedBy: 'system',
        );

        // 9. Flush to persist all changes in the current transaction
        $this->em->flush();

        return [
            'ticket_id' => $ticketId,
            'assigned' => true,
            'agent_id' => $selectedAgent->getId(),
            'reason' => 'success',
        ];
    }

    /**
     * Persist collected domain events to outbox table (post-commit).
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

            if ($event instanceof \App\Ticket\TicketUpdatedEvent) {
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
