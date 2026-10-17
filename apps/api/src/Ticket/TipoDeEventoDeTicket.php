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
final class TipoDeEventoDeTicket
{
    public const VENTANA_VENCIDA_CERRADO = 'window_expired_closed';
    public const VENTANA_REABIERTO = 'window_reopened';

    // Manual closure/resolution audit trail (ticket-closure-authorization).
    // See ClasificadorDeAuditoriaDeCierre for the truth table that reaches these.
    public const MANUAL_RESUELTO_RESPONSABLE = 'manual_resolved_owner';
    public const MANUAL_RESUELTO_FORZADO = 'manual_resolved_override';
    public const MANUAL_RESUELTO_SIN_RESPONSABLE = 'manual_resolved_unowned';
    public const MANUAL_CERRADO_RESPONSABLE = 'manual_closed_owner';
    public const MANUAL_CERRADO_FORZADO = 'manual_closed_override';
    public const MANUAL_CERRADO_SIN_RESPONSABLE = 'manual_closed_unowned';
}
