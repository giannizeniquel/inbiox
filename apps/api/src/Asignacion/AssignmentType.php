<?php

declare(strict_types=1);

namespace App\Asignacion;

/**
 * Types of ticket ownership changes for the audit trail.
 *
 * Every assignment path (auto, manual, reassign, unassign) creates an
 * Assignment row with one of these types. AssignmentService is the ONLY
 * place that creates Assignment rows.
 */
enum AssignmentType: string
{
    case AUTO_ASSIGN = 'AUTO_ASSIGN';
    case MANUAL_ASSIGN = 'MANUAL_ASSIGN';
    case REASSIGN = 'REASSIGN';
    case UNASSIGN = 'UNASSIGN';
}
