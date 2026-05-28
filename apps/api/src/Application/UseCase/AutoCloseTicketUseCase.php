<?php

namespace App\Application\UseCase;

use App\Domain\Ticket\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Auto-closes resolved tickets after inactivity period.
 * 
 * IMPORTANT: Only INBOUND (customer) messages after resolved_at prevent auto-close.
 * OUTBOUND (agent) messages do NOT prevent auto-close.
 * 
 * Configurable hours (default 24).
 */
class AutoCloseTicketUseCase
{
    private const INACTIVITY_HOURS = 24;

    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
    ) {}

    /**
     * Close eligible resolved tickets
     * 
     * A ticket is eligible for auto-close if:
     * 1. Status is RESOLVED
     * 2. resolved_at is set
     * 3. closed_at is NULL
     * 4. There are NO inbound messages AFTER resolved_at
     * 
     * @param int|null $inactivityHours Override default hours
     * @return array
     */
    public function execute(?int $inactivityHours = null): array
    {
        $hours = $inactivityHours ?? self::INACTIVITY_HOURS;

        return $this->em->getConnection()->transactional(function() use ($hours) {
            // Find resolved tickets where:
            // - No inbound messages exist after resolved_at
            // - resolved_at is at least $hours hours ago
            $sql = <<<SQL
                UPDATE tickets 
                SET status = ?, closed_at = NOW(), updated_at = NOW() 
                WHERE status = ? 
                AND resolved_at IS NOT NULL
                AND closed_at IS NULL
                AND TIMESTAMPDIFF(HOUR, resolved_at, NOW()) >= ?
                AND NOT EXISTS (
                    SELECT 1 FROM messages m 
                    WHERE m.conversation_id = tickets.conversation_id 
                    AND m.direction = 'inbound'
                    AND m.created_at > tickets.resolved_at
                )
                SQL;

            $stmt = $this->em->getConnection()->executeStatement($sql, [
                TicketStatus::CLOSED->value,
                TicketStatus::RESOLVED->value,
                $hours,
            ]);

            return [
                'status' => 'ok',
                'affected_rows' => $stmt->rowCount(),
                'hours_threshold' => $hours,
            ];
        });
    }

    /**
     * Simpler version: close resolved tickets with no recent activity
     * This version is more reliable for testing but doesn't distinguish 
     * between inbound/outbound messages
     * 
     * @deprecated Use execute() for proper inbound/outbound filtering
     */
    public function executeSimple(int $hours = 24): array
    {
        return $this->em->getConnection()->transactional(function() use ($hours) {
            $sql = <<<SQL
                UPDATE tickets 
                SET status = ?, closed_at = NOW(), updated_at = NOW() 
                WHERE status = ? 
                AND resolved_at IS NOT NULL
                AND closed_at IS NULL
                AND TIMESTAMPDIFF(HOUR, COALESCE(
                    (SELECT MAX(m.created_at) FROM messages m WHERE m.conversation_id = tickets.conversation_id),
                    resolved_at
                ), NOW()) >= ?
                AND assigned_agent_id IS NOT NULL
                SQL;

            $stmt = $this->em->getConnection()->executeStatement($sql, [
                TicketStatus::CLOSED->value,
                TicketStatus::RESOLVED->value,
                $hours,
            ]);

            return [
                'status' => 'ok',
                'affected_rows' => $stmt->rowCount(),
                'hours_threshold' => $hours,
            ];
        });
    }
}