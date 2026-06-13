<?php

namespace App\Exception;

/**
 * Thrown when the WhatsApp Graph API returns an error response.
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
