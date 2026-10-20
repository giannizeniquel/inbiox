<?php

declare(strict_types=1);

namespace App\Compartido;

/**
 * Excepción que se lanza cuando una transición de estado no está permitida.
 *
 * La usa TicketVoter al intentar asignar un ticket en estado resolved/closed.
 */
class ConflictoDeEstadoException extends \RuntimeException
{
    public function __construct(string $message = 'State conflict', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
