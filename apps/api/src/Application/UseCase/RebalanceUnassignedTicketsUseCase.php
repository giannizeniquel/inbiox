<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Retry assignment of all unassigned tickets that are still active.
 *
 * Each ticket gets its own transaction — failure on ticket #5 does NOT
 * roll back tickets #1-#4.
 *
 * Constraints:
 *   - NEVER touches resolved/closed tickets (only new/active unassigned)
 *   - NEVER overrides manual assignments (only unassigned)
 *   - Each ticket is independent (separate transaction)
 *   - Assignment type is always AUTO_ASSIGN (not MANUAL_ASSIGN)
 */
final class RebalanceUnassignedTicketsUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private AutoAssignTicketUseCase $autoAssignUseCase,
    ) {}

    /**
     * Execute rebalance for all unassigned active tickets.
     *
     * @return array{attempted: int, assigned: int, skipped: int}
     */
    public function execute(): array
    {
        $conn = $this->em->getConnection();

        // 1. Select unassigned tickets with active statuses (new, active)
        //    NEVER touches resolved or closed tickets.
        $ticketRows = $conn->executeQuery(
            "SELECT id FROM tickets
             WHERE assigned_agent_id IS NULL
             AND status IN ('new', 'active')
             ORDER BY created_at ASC",
        )->fetchAllAssociative();

        $attempted = \count($ticketRows);
        $assigned = 0;
        $skipped = 0;

        // 2. For each ticket: one independent transaction
        foreach ($ticketRows as $row) {
            $ticketId = (int) $row['id'];

            // AutoAssignTicketUseCase::execute() opens its own transaction
            // and persists outbox events per ticket.
            $result = $this->autoAssignUseCase->execute($ticketId);

            if ($result['assigned']) {
                ++$assigned;
            } else {
                ++$skipped;
            }
        }

        return [
            'attempted' => $attempted,
            'assigned' => $assigned,
            'skipped' => $skipped,
        ];
    }
}
