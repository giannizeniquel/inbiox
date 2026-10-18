<?php

declare(strict_types=1);

namespace App\Ticket;

/**
 * Pure predicate for the resolved/closed ownership guard (design.md D3-D5).
 *
 * Extracted out of `HandleOutboundMessageUseCase::executeTransactionWithRetry()`
 * so the guard's truth table (status x privileged x owner x agentId<=0) can
 * be unit-tested directly with zero mocks, instead of driving the whole
 * transactional use case through a heavy mock graph just to reach a single
 * `if` branch (strict-tdd Extract-Before-Mock rule). Behavior is identical
 * to the inlined contract in design.md.
 */
final class TicketResponsabilidadGuard
{
    /**
     * A resolved/closed ticket freezes ownership for reply-driven changes.
     */
    public static function isResueltoOCerrado(string $estado): bool
    {
        return $estado === EstadoDeTicket::RESUELTO->value || $estado === EstadoDeTicket::CERRADO->value;
    }

    /**
     * Whether an outbound reply from $agenteId must be BLOCKED (StateConflictException)
     * because the ticket is resolved/closed, the sender is not privileged, and the
     * sender is not the current owner.
     *
     * `$agenteId <= 0` (system/no-agent callers) never blocks — preserves every
     * existing `agentId: 0` call site.
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
