<?php

namespace App\Exception;

use RuntimeException;

/**
 * Thrown when optimistic locking retries are exhausted.
 * Indicates a concurrency conflict that could not be resolved.
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