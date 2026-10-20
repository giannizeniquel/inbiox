<?php

declare(strict_types=1);

namespace App\Contacto;

/**
 * Estado de un contacto (`contacts.status`). Borrar un contacto lo deja `inactivo`.
 */
enum EstadoDeContacto: string
{
    case ACTIVO = 'activo';
    case INACTIVO = 'inactivo';
}
