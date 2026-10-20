<?php

declare(strict_types=1);

namespace App\Ticket;

/**
 * Constantes de tipo para las filas de `ticket_events` introducidas por el cambio
 * de cumplimiento de la ventana de conversación de 24 h de WhatsApp.
 *
 * Los tipos existentes como string crudo (`auto_closed`, `auto_reopened`, `tag_added`,
 * `tag_removed`, ...) se dejan intencionalmente como están — refactorizarlos queda
 * fuera del alcance de este cambio (design.md).
 */
final class TipoDeEventoDeTicket
{
    public const VENTANA_VENCIDA_CERRADO = 'window_expired_closed';
    public const VENTANA_REABIERTO = 'window_reopened';

    // Registro de auditoría del cierre/resolución manual (ticket-closure-authorization).
    // Ver ClasificadorDeAuditoriaDeCierre para la tabla de verdad que llega a estos.
    public const MANUAL_RESUELTO_RESPONSABLE = 'manual_resolved_owner';
    public const MANUAL_RESUELTO_FORZADO = 'manual_resolved_override';
    public const MANUAL_RESUELTO_SIN_RESPONSABLE = 'manual_resolved_unowned';
    public const MANUAL_CERRADO_RESPONSABLE = 'manual_closed_owner';
    public const MANUAL_CERRADO_FORZADO = 'manual_closed_override';
    public const MANUAL_CERRADO_SIN_RESPONSABLE = 'manual_closed_unowned';
}
