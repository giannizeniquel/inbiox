<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Compartido\RecolectorDeEventos;

/**
 * Evento de dominio marcador para cambios de turno de un agente.
 *
 * Su nombre de clase era el valor guardado en `outbox_events.event_type`; ahora se guarda
 * `TipoDeEventoDeOutbox::TURNO_DE_AGENTE_CAMBIADO` y el nombre de clase queda solo como alias legado.
 * No se recolecta mediante RecolectorDeEventos: el EventoOutbox se crea directamente.
 */
final class AgentShiftChangedEvent
{
}
