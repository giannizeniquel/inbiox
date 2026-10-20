<?php

declare(strict_types=1);

namespace App\Ticket;

/**
 * Predicado puro para la guarda de responsabilidad de resolved/closed (design.md D3-D5).
 *
 * Extraído de `ProcesarMensajeSalienteUseCase::executeTransactionWithRetry()`
 * para que la tabla de verdad de la guarda (estado x privilegiado x responsable x agentId<=0) pueda
 * probarse unitariamente de forma directa sin mocks, en lugar de recorrer todo
 * el caso de uso transaccional con un grafo pesado de mocks solo para llegar a una única
 * rama `if` (regla Extract-Before-Mock de strict-tdd). El comportamiento es idéntico
 * al contrato que estaba en línea en design.md.
 */
final class TicketResponsabilidadGuard
{
    /**
     * Un ticket resolved/closed congela la responsabilidad ante los cambios causados por respuestas.
     */
    public static function isResueltoOCerrado(string $estado): bool
    {
        return $estado === EstadoDeTicket::RESUELTO->value || $estado === EstadoDeTicket::CERRADO->value;
    }

    /**
     * Indica si una respuesta saliente de $agenteId debe BLOQUEARSE (ConflictoDeEstadoException)
     * porque el ticket está resolved/closed, el remitente no es privilegiado y el
     * remitente no es el responsable actual.
     *
     * `$agenteId <= 0` (llamadas del sistema o sin agente) nunca bloquea — conserva todas
     * las llamadas existentes con `agentId: 0`.
     */
    public static function bloqueaReasignacion(
        string $estado,
        bool $remitenteEsPrivilegiado,
        int $agenteId,
        ?int $agenteAsignadoId,
    ): bool {
        if (!self::isResueltoOCerrado($estado)) {
            return false;
        }

        return !$remitenteEsPrivilegiado && $agenteId > 0 && $agenteAsignadoId !== $agenteId;
    }
}
