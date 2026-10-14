<?php

namespace App\Ticket\UseCase;

use App\Asignacion\AssignmentService;
use App\Agente\Agent;
use App\Asignacion\AssignmentType;
use App\Ticket\TicketEvent;
use App\Ticket\TicketEventType;
use App\Ticket\Ticket;
use App\Ticket\TicketStateMachine;
use App\Ticket\TicketStatus;
use App\Agente\Repository\AgentRepository;
use App\Ticket\Repository\TicketRepository;
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
    private function recordUnassignIfOwner(int $ticketId, string $actor = 'system'): void
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
            assignedBy: $actor,
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
        bool $setResolved = false,
        string $actor = 'system'
    ): array {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $targetStatus, $setFirstResponse, $setResolved, $actor) {
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
                $this->recordUnassignIfOwner($ticketId, $actor);
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

            // Set closed_at when landing on closed via this generic entry
            // point (design.md D7) — the agent's primary own-ticket close
            // path once /close is structurally unowned-only (D3).
            if ($newStatus === TicketStatus::CLOSED->value && empty($ticket['closed_at'])) {
                $updates[] = 'closed_at = NOW()';
            }

            // Execute update
            $sql = "UPDATE tickets SET " . implode(', ', $updates) . " WHERE id = ?";
            $this->em->getConnection()->executeStatement($sql, $params);

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $currentStatus,
                'new_status' => $newStatus,
                'previous_assigned_agent_id' => isset($ticket['assigned_agent_id'])
                    ? (int) $ticket['assigned_agent_id'] : null,
                'reason' => 'success',
            ];
        });
    }

    /**
     * Close a ticket because its WhatsApp 24h conversation window has
     * elapsed (design.md D2/D3).
     *
     * Unlike `close()`, this does not require the ticket to be `resolved` —
     * validity is delegated entirely to the widened FSM
     * (`new|active|waiting|resolved → closed`, task 0.9). Writes a
     * `TicketEvent(WINDOW_EXPIRED_CLOSED, 'system')` row so the closure is
     * distinguishable from a human close in the audit trail (ticket-closure-
     * audit capability).
     */
    public function closeDueToWindowExpiry(int $ticketId): array
    {
        return $this->em->getConnection()->transactional(function () use ($ticketId) {
            $ticket = $this->em->getConnection()->executeQuery(
                "SELECT * FROM tickets WHERE id = ? FOR UPDATE",
                [$ticketId]
            )->fetchAssociative();

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            $currentStatus = $ticket['status'];

            // Validity delegated to the FSM (task 0.9's widening); rejects
            // an already-closed ticket via its own guard — no special-cased
            // check needed here.
            $newStatus = $this->stateMachine->transition($currentStatus, TicketStatus::CLOSED->value);

            if (!empty($ticket['assigned_agent_id'])) {
                $this->recordUnassignIfOwner($ticketId);
                $this->em->flush();
            }

            $this->em->getConnection()->executeStatement(
                "UPDATE tickets SET status = ?, closed_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$newStatus, $ticketId]
            );

            $event = new TicketEvent($ticketId, TicketEventType::WINDOW_EXPIRED_CLOSED, 'system');
            $this->em->persist($event);
            $this->em->flush();

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $currentStatus,
                'new_status' => $newStatus,
                'reason' => 'window_expired',
            ];
        });
    }

    /**
     * Reopen a ticket that was closed by the WhatsApp 24h window-expiry
     * process, in response to a new inbound customer message (design.md D1).
     *
     * This method deliberately NEVER calls `$this->stateMachine` — no
     * `closed → *` entry exists in `TicketStateMachine::TRANSITIONS`, and
     * none is ever added, so this reopen path is structurally unreachable
     * from any human-facing endpoint (e.g. `POST /tickets/{id}/transition`).
     * It has its own independent guard, mirroring how `closeDueToWindowExpiry()`
     * and `close()` already coexist without colliding.
     *
     * Guard 2 (latest event type) is re-checked here, inside the locked
     * transaction, rather than trusted from the repository lookup that fed
     * the caller: the two calls are separated in time and a concurrent
     * close could change the reason in between.
     */
    public function reopenDueToInboundMessage(int $ticketId): array
    {
        return $this->em->getConnection()->transactional(function () use ($ticketId) {
            $ticket = $this->em->getConnection()->executeQuery(
                "SELECT * FROM tickets WHERE id = ? FOR UPDATE",
                [$ticketId]
            )->fetchAssociative();

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            // Guard 1: only a closed ticket is eligible.
            if ($ticket['status'] !== TicketStatus::CLOSED->value) {
                throw new \InvalidArgumentException('Only closed tickets can be reopened: ' . $ticketId);
            }

            // Guard 2: the ticket's latest event must be a window-expiry
            // closure — re-checked here under the lock, not trusted from
            // the caller's earlier repository lookup.
            $latestType = $this->em->getConnection()->fetchOne(
                'SELECT type FROM ticket_events WHERE ticket_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
                [$ticketId]
            );
            if ($latestType !== TicketEventType::WINDOW_EXPIRED_CLOSED) {
                throw new \InvalidArgumentException(
                    'Ticket ' . $ticketId . ' is not eligible for system-triggered reopen'
                );
            }

            $newStatus = TicketStatus::ACTIVE->value;

            $this->em->getConnection()->executeStatement(
                "UPDATE tickets SET status = ?, closed_at = NULL, updated_at = NOW() WHERE id = ?",
                [$newStatus, $ticketId]
            );

            $event = new TicketEvent($ticketId, TicketEventType::WINDOW_REOPENED, 'system');
            $this->em->persist($event);
            $this->em->flush();

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $ticket['status'],
                'new_status' => $newStatus,
                'reason' => 'window_reopened',
            ];
        });
    }

    /**
     * Resolve a ticket (manual or automated)
     */
    public function resolve(int $ticketId, string $actor = 'system'): array
    {
        return $this->execute($ticketId, TicketStatus::RESOLVED->value, true, true, $actor);
    }

    /**
     * Close a resolved ticket
     */
    public function close(int $ticketId, string $actor = 'system'): array
    {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $actor) {
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
                $this->recordUnassignIfOwner($ticketId, $actor);
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
                'previous_assigned_agent_id' => isset($ticket['assigned_agent_id'])
                    ? (int) $ticket['assigned_agent_id'] : null,
                'reason' => 'success',
            ];
        });
    }
}