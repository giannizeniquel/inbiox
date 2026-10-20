<?php

namespace App\Compartido;

use RuntimeException;

/**
 * Se lanza cuando se agotan los reintentos del bloqueo optimista.
 * Indica un conflicto de concurrencia que no se pudo resolver.
 */
class ConcurrencyException extends RuntimeException
{
    public function __construct(
        string $message = 'Concurrent modification detected. Please retry the operation.',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}