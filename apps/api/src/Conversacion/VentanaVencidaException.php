<?php

declare(strict_types=1);

namespace App\Conversacion;

use App\Compartido\Graph\GraphApiException;

/**
 * Thrown when a caller attempts to send free text to a customer after the
 * WhatsApp 24h conversation window has elapsed.
 *
 * Deliberately distinct from `GraphApiException(132000)` (design.md D5):
 * reusing Meta's own error code would lie about the exception's origin (it
 * never reached Meta) and would be indistinguishable in logs from a genuine
 * Meta rejection.
 */
class VentanaVencidaException extends \RuntimeException
{
    public function __construct(
        private readonly int $ticketId,
        private readonly ?\DateTimeImmutable $lastInboundMessageAt,
        string $message = 'Conversation window has expired; free-text sending is not allowed.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getTicketId(): int
    {
        return $this->ticketId;
    }

    public function getLastInboundMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastInboundMessageAt;
    }
}
