<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

/**
 * Pure predicate for the resolved/closed ownership guard (design.md D3-D5).
 *
 * Extracted out of `HandleOutboundMessageUseCase::executeTransactionWithRetry()`
 * so the guard's truth table (status x privileged x owner x agentId<=0) can
 * be unit-tested directly with zero mocks, instead of driving the whole
 * transactional use case through a heavy mock graph just to reach a single
 * `if` branch (strict-tdd Extract-Before-Mock rule). Behavior is identical
 * to the inlined contract in design.md.
 */
final class TicketOwnershipGuard
{
    /**
     * A resolved/closed ticket freezes ownership for reply-driven changes.
     */
    public static function isResolvedOrClosed(string $status): bool
    {
        return $status === TicketStatus::RESOLVED->value || $status === TicketStatus::CLOSED->value;
    }

    /**
     * Whether an outbound reply from $agentId must be BLOCKED (StateConflictException)
     * because the ticket is resolved/closed, the sender is not privileged, and the
     * sender is not the current owner.
     *
     * `$agentId <= 0` (system/no-agent callers) never blocks — preserves every
     * existing `agentId: 0` call site.
     */
    public static function blocksReassignment(
        string $status,
        bool $senderIsPrivileged,
        int $agentId,
        ?int $assignedAgentId,
    ): bool {
        if (!self::isResolvedOrClosed($status)) {
            return false;
        }

        return !$senderIsPrivileged && $agentId > 0 && $assignedAgentId !== $agentId;
    }
}
