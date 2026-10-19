<?php

declare(strict_types=1);

namespace App\Compartido;

/**
 * Exception thrown when a state transition is not allowed.
 *
 * Used by TicketVoter when trying to assign a ticket in resolved/closed state.
 */
class ConflictoDeEstadoException extends \RuntimeException
{
    public function __construct(string $message = 'State conflict', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
