<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Compartido\ConflictoDeEstadoException;

/**
 * Exception thrown when a ticket assignment targets an agent that is not
 * currently Activo (per DisponibilidadDeAgenteService::isActiveNow()).
 *
 * HARD BLOCK for every role (ROLE_AGENT, ROLE_SUPERVISOR, ROLE_ADMIN) — no
 * bypass. Thrown inside AsignarTicketUseCase's transaction, after the
 * pessimistic lock, before registrarCambioDeResponsable(): a rejection writes no
 * assignment row and emits no event; the transaction rolls back.
 *
 * Subclass of ConflictoDeEstadoException so existing generic 409 handling still
 * applies if a caller only catches the parent type, while TicketController
 * gets a machine-readable discriminator (AGENT_NOT_ACTIVE) via a
 * subclass-first catch arm.
 */
class AgenteInactivoException extends ConflictoDeEstadoException
{
    public static function paraAgente(int $agenteId, string $nombreDeAgente): self
    {
        return new self(\sprintf(
            'Agent "%s" (id=%d) is not currently active and cannot be assigned',
            $nombreDeAgente,
            $agenteId,
        ));
    }
}
