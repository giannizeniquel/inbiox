<?php

namespace App\Ticket;

/**
 * Finite State Machine for Ticket workflow.
 *
 * Valid transitions:
 * - new → active (first agent response)
 * - new → closed (WhatsApp 24h window expired before any agent response)
 * - active → waiting (agent waits for customer)
 * - active → closed (WhatsApp 24h window expired)
 * - waiting → active (customer responds)
 * - waiting → closed (WhatsApp 24h window expired)
 * - active → resolved (ticket resolved)
 * - waiting → resolved (ticket resolved)
 * - resolved → waiting (customer responds - auto-reopen)
 * - resolved → closed (auto-close after inactivity)
 *
 * `new|active|waiting → closed` was added for WhatsApp 24h conversation-
 * window compliance (design.md D2 — Option A: never routed through
 * `resolved`, so `resolved_at` and resolution metrics stay uncontaminated).
 * This widening only affects the CLOSING side. `closed` remains strictly
 * terminal: no `closed → *` transition exists in this table, by design.
 * The system-triggered reopen path (`TransitionTicketStatusUseCase::
 * reopenDueToInboundMessage()`) never routes through this shared table —
 * see design.md D1.
 */
class TicketStateMachine
{
    /**
     * @var array<string, array<string, true>> Valid transitions
     */
    private const TRANSITIONS = [
        TicketStatus::NEW->value => [
            TicketStatus::ACTIVE->value => true,
            TicketStatus::CLOSED->value => true,
        ],
        TicketStatus::ACTIVE->value => [
            TicketStatus::WAITING->value => true,
            TicketStatus::RESOLVED->value => true,
            TicketStatus::CLOSED->value => true,
        ],
        TicketStatus::WAITING->value => [
            TicketStatus::ACTIVE->value => true,
            TicketStatus::RESOLVED->value => true,
            TicketStatus::CLOSED->value => true,
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
     * Check if status is terminal (no more transitions)
     *
     * @param string $status
     * @return bool
     */
    public function isTerminal(string $status): bool
    {
        return $status === TicketStatus::CLOSED->value;
    }
}
