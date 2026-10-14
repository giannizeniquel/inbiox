<?php

declare(strict_types=1);

namespace App\Contacto;

/**
 * Whole-file rejection for CSV contact import.
 *
 * Thrown when the uploaded file itself is unusable (missing, too large, wrong
 * type, missing required column, empty, or exceeding the row cap) — never for
 * per-row validation failures, which are reported in the partial-success
 * result instead. The controller maps `getReason()` to HTTP 400.
 */
class ImportRejectedException extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
