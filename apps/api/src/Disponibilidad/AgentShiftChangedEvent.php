<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Compartido\RecolectorDeEventos;

/**
 * Marker domain event for agent shift changes.
 *
 * FQCN used by TipoDeEventoMapper to map to TipoDeEventoEnTiempoReal::AGENT_SHIFT_CHANGED.
 * Not collected via RecolectorDeEventos — EventoOutbox is created directly.
 */
final class AgentShiftChangedEvent
{
}
