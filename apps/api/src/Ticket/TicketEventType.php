<?php

declare(strict_types=1);

namespace App\Ticket;

/**
 * Type constants for `ticket_events` rows introduced by the WhatsApp 24h
 * conversation-window compliance change.
 *
 * Existing raw-string types (`auto_closed`, `auto_reopened`, `tag_added`,
 * `tag_removed`, ...) are intentionally left as-is — refactoring them is
 * out of scope for this change (design.md).
 */
final class TicketEventType
{
    public const WINDOW_EXPIRED_CLOSED = 'window_expired_closed';
    public const WINDOW_REOPENED = 'window_reopened';

    // Manual closure/resolution audit trail (ticket-closure-authorization).
    // See ClosureAuditClassifier for the truth table that reaches these.
    public const MANUAL_RESOLVED_OWNER = 'manual_resolved_owner';
    public const MANUAL_RESOLVED_OVERRIDE = 'manual_resolved_override';
    public const MANUAL_RESOLVED_UNOWNED = 'manual_resolved_unowned';
    public const MANUAL_CLOSED_OWNER = 'manual_closed_owner';
    public const MANUAL_CLOSED_OVERRIDE = 'manual_closed_override';
    public const MANUAL_CLOSED_UNOWNED = 'manual_closed_unowned';
}
