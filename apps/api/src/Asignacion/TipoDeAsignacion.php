<?php

declare(strict_types=1);

namespace App\Asignacion;

/**
 * Tipos de cambio de responsable de un ticket para el registro de auditoría.
 *
 * Cada vía de asignación (automática, manual, reasignación, desasignación) crea una
 * fila de Asignacion con uno de estos tipos. ResponsabilidadDeTicketService es el ÚNICO
 * lugar que crea filas de Asignacion.
 */
enum TipoDeAsignacion: string
{
    case AUTOMATICA = 'automatica';
    case MANUAL = 'manual';
    case REASIGNACION = 'reasignacion';
    case DESASIGNACION = 'desasignacion';
}
