<?php

namespace App\Domain\Ticket;

/**
 * Finite State Machine for Ticket workflow.
 *
 * Valid transitions:
 * - new → active (first agent response)
 * - active → waiting (agent waits for customer)
 * - waiting → active (customer responds)
 * - active → resolved (ticket resolved)
 * - waiting → resolved (ticket resolved)
 * - resolved → waiting (customer responds - auto-reopen)
 * - resolved → closed (auto-close after inactivity)
 */
class TicketStateMachine
{
    /**
     * @var array<string, array<string, true>> Valid transitions
     */
    private const TRANSITIONS = [
        TicketStatus::NEW->value => [
            TicketStatus::ACTIVE->value => true,
        ],
        TicketStatus::ACTIVE->value => [
            TicketStatus::WAITING->value => true,
            TicketStatus::RESOLVED->value => true,
        ],
        TicketStatus::WAITING->value => [
            TicketStatus::ACTIVE->value => true,
            TicketStatus::RESOLVED->value => true,
        ],
        TicketStatus::RESOLVED->value => [
            TicketStatus::WAITING->value => true,
            TicketStatus::CLOSED->value => true,
        ],
        // closed is terminal - no transitions allowed
    ];

    /**
     * Check if a transition is valid
     *
     * @param string $from Current status
     * @param string $to Target status
     * @return bool
     */
    public function canTransition(string $from, string $to): bool
    {
        return isset(self::TRANSITIONS[$from][$to]) && self::TRANSITIONS[$from][$to] === true;
    }

    /**
     * Validate and return new status, or throw exception
     *
     * @param string $currentStatus
     * @param string $targetStatus
     * @return string The new status
     * @throws InvalidTransitionException If transition is invalid
     */
    public function transition(string $currentStatus, string $targetStatus): string
    {
        if (!$this->canTransition($currentStatus, $targetStatus)) {
            throw new InvalidTransitionException(
                sprintf(
                    'Invalid transition from "%s" to "%s". Valid transitions from "%s" are: %s',
                    $currentStatus,
                    $targetStatus,
                    $currentStatus,
                    implode(', ', array_keys(self::TRANSITIONS[$currentStatus] ?? [])) ?: 'none'
                )
            );
        }

        return $targetStatus;
    }

    /**
     * Get valid target statuses for a given status
     *
     * @param string $currentStatus
     * @return array<string>
     */
    public function getValidTransitions(string $currentStatus): array
    {
        return array_keys(self::TRANSITIONS[$currentStatus] ?? []);
    }

    /**
     * Check if status is terminal (no more transitions)
     *
     * @param string $status
     * @return bool
     */
    public function isTerminal(string $status): bool
    {
        return $status === TicketStatus::CLOSED->value;
    }

    /**
     * Check if status allows SLA (active tracking)
     *
     * @param string $status
     * @return bool
     */
    public function isActiveForSLA(string $status): bool
    {
        return in_array($status, [
            TicketStatus::NEW->value,
            TicketStatus::ACTIVE->value,
            TicketStatus::WAITING->value,
        ], true);
    }
}

class InvalidTransitionException extends \Exception
{
}