<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Compartido\RecolectorDeEventos;

/**
 * Evento de dominio marcador para cambios de disponibilidad de un agente.
 *
 * FQCN que TipoDeEventoMapper usa para mapearlo a TipoDeEventoEnTiempoReal::DISPONIBILIDAD_DE_AGENTE_CAMBIADA.
 * No se recolecta mediante RecolectorDeEventos: el EventoOutbox se crea directamente.
 */
final class AgentAvailabilityChangedEvent
{
}
