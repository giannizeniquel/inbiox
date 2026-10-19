<?php

declare(strict_types=1);

namespace App\Asignacion;

/**
 * Types of ticket ownership changes for the audit trail.
 *
 * Every assignment path (auto, manual, reassign, unassign) creates an
 * Asignacion row with one of these types. ResponsabilidadDeTicketService is the ONLY
 * place that creates Asignacion rows.
 */
enum TipoDeAsignacion: string
{
    case AUTOMATICA = 'AUTO_ASSIGN';
    case MANUAL = 'MANUAL_ASSIGN';
    case REASIGNACION = 'REASSIGN';
    case DESASIGNACION = 'UNASSIGN';
}
