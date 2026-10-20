<?php

declare(strict_types=1);

namespace App\Ticket;

use App\Administracion\Usuario;

/**
 * Clasifica una acción manual de resolver/cerrar en el resultado de auditoría
 * owner/override/unowned (design.md D2), y arma la referencia de actor con espacio de nombres
 * guardada en `ticket_events.source` / `assignments.assigned_by` (design.md D5).
 *
 * Pura, sin dependencias — sin Doctrine, sin HTTP.
 */
final class ClasificadorDeAuditoriaDeCierre
{
    /**
     * @param string   $nuevoEstado       'resuelto' | 'cerrado' (después de la transición)
     * @param int|null $responsableAnteriorId ticket.assigned_agent_id capturado bajo
     *                                  el propio bloqueo `FOR UPDATE` del caso de uso (D4)
     * @param int|null $agenteActuanteId   $usuario->getAgent()?->getId(), null para un
     *                                  admin/supervisor sin agente
     */
    public function clasificar(string $nuevoEstado, ?int $responsableAnteriorId, ?int $agenteActuanteId): string
    {
        $isCerrado = $nuevoEstado === EstadoDeTicket::CERRADO->value;

        if ($responsableAnteriorId === null) {
            return $isCerrado ? TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE : TipoDeEventoDeTicket::MANUAL_RESUELTO_SIN_RESPONSABLE;
        }

        if ($responsableAnteriorId === $agenteActuanteId) {
            return $isCerrado ? TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE : TipoDeEventoDeTicket::MANUAL_RESUELTO_RESPONSABLE;
        }

        return $isCerrado ? TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO : TipoDeEventoDeTicket::MANUAL_RESUELTO_FORZADO;
    }

    /**
     * Referencia de actor compacta y con espacio de nombres para `EventoDeTicket.source` /
     * `Asignacion.asignadoPor` (design.md D5).
     */
    public function referenciaDeActor(Usuario $usuario): string
    {
        return \sprintf('u:%d', $usuario->getId());
    }
}
