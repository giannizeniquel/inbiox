<?php

namespace App\Application\UseCase;

use App\Application\Service\AssignmentService;
use App\Domain\Agent\Agent;
use App\Domain\Assignment\AssignmentType;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStateMachine;
use App\Domain\Ticket\TicketStatus;
use App\Repository\AgentRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transitions ticket status following FSM rules.
 * Sets SLA timestamps deterministically.
 *
 * On resolve/close, if the ticket has an owner, creates an UNASSIGN
 * Assignment audit row via AssignmentService to close the audit gap (W1).
 */
class TransitionTicketStatusUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private TicketStateMachine $stateMachine,
        private AssignmentService $assignmentService,
        private AgentRepository $agentRepository,
    ) {}

    /**
     * Record UNASSIGN audit if the ticket owner is being resolved/closed.
     */
    private function recordUnassignIfOwner(int $ticketId): void
    {
        $ticketEntity = $this->ticketRepo->findById($ticketId);
        if ($ticketEntity === null) {
            return;
        }

        $ownerId = $ticketEntity->getAssignedAgentId();
        if ($ownerId === null) {
            return;
        }

        $agent = $this->agentRepository->findById($ownerId);
        if ($agent === null) {
            return;
        }

        $this->assignmentService->recordOwnershipChange(
            ticket: $ticketEntity,
            agent: null,
            previousAgent: $agent,
            type: AssignmentType::UNASSIGN,
            assignedBy: 'system',
        );
    }

    /**
     * Transition ticket to new status with FSM validation
     *
     * @param int $ticketId
     * @param string $targetStatus
     * @param bool $setFirstResponse - whether to set first_response_at (agent reply)
     * @param bool $setResolved - whether to set resolved_at
     * @return array
     */
    public function execute(
        int $ticketId,
        string $targetStatus,
        bool $setFirstResponse = false,
        bool $setResolved = false
    ): array {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $targetStatus, $setFirstResponse, $setResolved) {
            // Lock ticket for update
            $ticket = $this->em->getConnection()->executeQuery(
                "SELECT * FROM tickets WHERE id = ? FOR UPDATE",
                [$ticketId]
            )->fetchAssociative();

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            $currentStatus = $ticket['status'];

            // Validate transition via FSM
            $newStatus = $this->stateMachine->transition($currentStatus, $targetStatus);

            // Record UNASSIGN audit for resolve/close transitions
            if (($newStatus === TicketStatus::RESOLVED->value || $newStatus === TicketStatus::CLOSED->value)
                && !empty($ticket['assigned_agent_id'])
            ) {
                $this->recordUnassignIfOwner($ticketId);
                // Flush entity changes (Assignment + ticket entity modifications)
                // so they are part of this transaction before the raw SQL status update
                $this->em->flush();
            }

            // Build update SQL
            $updates = ['status = ?', 'updated_at = NOW()'];
            $params = [$newStatus, $ticketId];

            // Set first_response_at if this is an agent reply and not already set
            if ($setFirstResponse && empty($ticket['first_response_at'])) {
                $updates[] = 'first_response_at = NOW()';
            }

            // Set resolved_at if transitioning to resolved
            if ($setResolved && $newStatus === TicketStatus::RESOLVED->value && empty($ticket['resolved_at'])) {
                $updates[] = 'resolved_at = NOW()';
            }

            // Execute update
            $sql = "UPDATE tickets SET " . implode(', ', $updates) . " WHERE id = ?";
            $this->em->getConnection()->executeStatement($sql, $params);

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $currentStatus,
                'new_status' => $newStatus,
                'reason' => 'success',
            ];
        });
    }

    /**
     * Resolve a ticket (manual or automated)
     */
    public function resolve(int $ticketId): array
    {
        return $this->execute($ticketId, TicketStatus::RESOLVED->value, true, true);
    }

    /**
     * Close a resolved ticket
     */
    public function close(int $ticketId): array
    {
        return $this->em->getConnection()->transactional(function() use ($ticketId) {
            $ticket = $this->em->getConnection()->executeQuery(
                "SELECT * FROM tickets WHERE id = ? FOR UPDATE",
                [$ticketId]
            )->fetchAssociative();

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            if ($ticket['status'] !== TicketStatus::RESOLVED->value) {
                throw new \InvalidArgumentException('Only resolved tickets can be closed');
            }

            // Record UNASSIGN audit if ticket has an owner
            if (!empty($ticket['assigned_agent_id'])) {
                $this->recordUnassignIfOwner($ticketId);
                $this->em->flush();
            }

            $this->em->getConnection()->executeStatement(
                "UPDATE tickets SET status = ?, closed_at = NOW(), updated_at = NOW() WHERE id = ?",
                [TicketStatus::CLOSED->value, $ticketId]
            );

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $ticket['status'],
                'new_status' => TicketStatus::CLOSED->value,
                'reason' => 'success',
            ];
        });
    }
}