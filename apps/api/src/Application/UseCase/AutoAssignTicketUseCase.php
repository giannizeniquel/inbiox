<?php

namespace App\Application\UseCase;

use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

class AutoAssignTicketUseCase
{
    // Active statuses for ticket counting
    private const ACTIVE_STATUSES = ['new', 'active', 'waiting'];

    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
    ) {}

    /**
     * Automatically assign a ticket to an eligible agent using least-loaded strategy
     *
     * @param int $ticketId
     * @return array
     */
    public function execute(int $ticketId): array
    {
        return $this->em->getConnection()->transactional(function() use ($ticketId) {
            return $this->executeInternal($ticketId);
        });
    }

    /**
     * Execute auto-assignment WITHOUT starting a new transaction
     * Use this when already inside a transaction
     *
     * @param int $ticketId
     * @return array
     */
    public function executeInTransaction(int $ticketId): array
    {
        return $this->executeInternal($ticketId);
    }

    /**
     * Internal execution logic
     *
     * @param int $ticketId
     * @return array
     */
    private function executeInternal(int $ticketId): array
    {
        // 1. Lock the ticket row for update
        $ticket = $this->em->getConnection()->executeQuery(
            "SELECT * FROM tickets WHERE id = ? FOR UPDATE",
            [$ticketId]
        )->fetchAssociative();

        if (!$ticket) {
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => null,
                'reason' => 'ticket_not_found',
            ];
        }

        // 2. If already assigned, exit (idempotent)
        if ($ticket['assigned_agent_id'] !== null) {
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => (int) $ticket['assigned_agent_id'],
                'reason' => 'already_assigned',
            ];
        }

        // 3. Fetch eligible agents FOR UPDATE
        $agents = $this->em->getConnection()->executeQuery(
            "SELECT * FROM agents 
             WHERE active = 1 AND is_available = 1 
             FOR UPDATE",
            []
        )->fetchAllAssociative();

        if (empty($agents)) {
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => null,
                'reason' => 'no_agents_available',
            ];
        }

        // 4. Get current active ticket counts per agent
        $activeStatuses = self::ACTIVE_STATUSES;
        $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        
        $countSql = "SELECT assigned_agent_id, COUNT(*) as cnt 
                   FROM tickets 
                   WHERE assigned_agent_id IS NOT NULL 
                   AND status IN ($statusPlaceholders) 
                   GROUP BY assigned_agent_id";
        
        $countStmt = $this->em->getConnection()->executeQuery($countSql, $activeStatuses);
        $counts = $countStmt->fetchAllAssociativeIndexed();
        
        // Map counts by agent_id
        $agentLoad = [];
        foreach ($counts as $agentId => $row) {
            $agentLoad[$agentId] = (int) $row['cnt'];
        }

        // 5. Filter agents by capacity and find least loaded
        $eligibleAgents = [];
        foreach ($agents as $agent) {
            $agentId = (string) $agent['id'];
            $currentLoad = $agentLoad[$agentId] ?? 0;
            
            if ($currentLoad < (int) $agent['max_active_tickets']) {
                $eligibleAgents[] = [
                    'id' => (int) $agent['id'],
                    'name' => $agent['name'],
                    'load' => $currentLoad,
                ];
            }
        }

        // 6. If no eligible agents after capacity filter, exit
        if (empty($eligibleAgents)) {
            return [
                'ticket_id' => $ticketId,
                'assigned' => false,
                'agent_id' => null,
                'reason' => 'all_agents_at_capacity',
            ];
        }

        // 7. Sort by load ASC, then by agent.id ASC (tie-breaker)
        usort($eligibleAgents, function($a, $b) {
            if ($a['load'] !== $b['load']) {
                return $a['load'] <=> $b['load'];
            }
            return $a['id'] <=> $b['id'];
        });

        // 8. Select the least loaded agent
        $selectedAgent = $eligibleAgents[0];

        // 9. Update ticket with assigned_agent_id and status in single query if new
        if ($ticket['status'] === TicketStatus::NEW->value) {
            // Status is NEW: update both assigned_agent_id AND status to active in one query
            $this->em->getConnection()->executeStatement(
                "UPDATE tickets SET assigned_agent_id = ?, status = ?, updated_at = NOW() WHERE id = ?",
                [$selectedAgent['id'], TicketStatus::ACTIVE->value, $ticketId]
            );
        } else {
            // Status is not NEW: only update assigned_agent_id
            $this->em->getConnection()->executeStatement(
                "UPDATE tickets SET assigned_agent_id = ?, updated_at = NOW() WHERE id = ?",
                [$selectedAgent['id'], $ticketId]
            );
        }

        return [
            'ticket_id' => $ticketId,
            'assigned' => true,
            'agent_id' => $selectedAgent['id'],
            'reason' => 'success',
        ];
    }
}