<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Compartido\RecolectorDeEventos;

/**
 * Marker domain event for agent availability changes.
 *
 * FQCN used by TipoDeEventoMapper to map to TipoDeEventoEnTiempoReal::DISPONIBILIDAD_DE_AGENTE_CAMBIADA.
 * Not collected via RecolectorDeEventos — EventoOutbox is created directly.
 */
final class AgentAvailabilityChangedEvent
{
}
