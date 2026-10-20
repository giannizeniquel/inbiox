<?php

declare(strict_types=1);

namespace App\Ticket;

/**
 * Tipos de las filas de `ticket_events` (valores guardados, `docs/contrato-api.md` sección 3.1).
 * La línea de tiempo del contacto expone los permitidos con el prefijo `ticket.`.
 */
final class TipoDeEventoDeTicket
{
    public const VENTANA_VENCIDA_CERRADO = 'ventana_vencida_cerrado';
    public const VENTANA_REABIERTO = 'ventana_reabierta';
    public const REAPERTURA_AUTOMATICA = 'reapertura_automatica';
    public const ETIQUETA_AGREGADA = 'etiqueta_agregada';

    // Registro de auditoría del cierre/resolución manual (ticket-closure-authorization).
    // Ver ClasificadorDeAuditoriaDeCierre para la tabla de verdad que llega a estos.
    public const MANUAL_RESUELTO_RESPONSABLE = 'resuelto_manual_por_responsable';
    public const MANUAL_RESUELTO_FORZADO = 'resuelto_manual_forzado';
    public const MANUAL_RESUELTO_SIN_RESPONSABLE = 'resuelto_manual_sin_responsable';
    public const MANUAL_CERRADO_RESPONSABLE = 'cerrado_manual_por_responsable';
    public const MANUAL_CERRADO_FORZADO = 'cerrado_manual_forzado';
    public const MANUAL_CERRADO_SIN_RESPONSABLE = 'cerrado_manual_sin_responsable';
}
