<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Compartido\RecolectorDeEventos;

/**
 * Evento de dominio marcador para cambios de turno de un agente.
 *
 * FQCN que TipoDeEventoMapper usa para mapearlo a TipoDeEventoEnTiempoReal::TURNO_DE_AGENTE_CAMBIADO.
 * No se recolecta mediante RecolectorDeEventos: el EventoOutbox se crea directamente.
 */
final class AgentShiftChangedEvent
{
}
