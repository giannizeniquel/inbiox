<?php

namespace App\Ticket;

/**
 * Filtro de asignación de la lista de tickets (q `filtro` de `GET /api/tickets`).
 * Un valor desconocido se trata como `todos`.
 */
enum FiltroDeBandeja: string
{
    case TODOS = 'todos';
    case MIOS = 'mios';
    case ASIGNADOS = 'asignados';
    case SIN_ASIGNAR = 'sin_asignar';
}
