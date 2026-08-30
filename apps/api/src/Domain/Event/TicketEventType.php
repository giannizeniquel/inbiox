<?php

declare(strict_types=1);

namespace App\Domain\Event;

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
}
