<?php

namespace App\Compartido\Graph;

/**
 * Se lanza cuando la Graph API de WhatsApp devuelve una respuesta de error.
 */
class GraphApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $graphCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getGraphCode(): int
    {
        return $this->graphCode;
    }
}
