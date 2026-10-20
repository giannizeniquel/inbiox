<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Compartido\ConflictoDeEstadoException;

/**
 * Excepción lanzada cuando una asignación de ticket apunta a un agente que no está
 * Activo en este momento (según DisponibilidadDeAgenteService::isActiveNow()).
 *
 * BLOQUEO ESTRICTO para todos los roles (ROLE_AGENTE, ROLE_SUPERVISOR, ROLE_ADMINISTRADOR), sin
 * excepciones. Se lanza dentro de la transacción de AsignarTicketUseCase, después del
 * lock pesimista y antes de registrarCambioDeResponsable(): un rechazo no escribe
 * ninguna fila de asignación ni emite eventos; la transacción hace rollback.
 *
 * Subclase de ConflictoDeEstadoException para que el manejo genérico de 409 existente
 * siga aplicando si quien llama solo captura el tipo padre, mientras que TicketController
 * obtiene un discriminador legible por máquina (AGENT_NOT_ACTIVE) mediante un
 * catch que captura primero la subclase.
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
