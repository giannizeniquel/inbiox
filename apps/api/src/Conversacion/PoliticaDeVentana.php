<?php

declare(strict_types=1);

namespace App\Conversacion;

/**
 * Dependency-free predicate for Meta's WhatsApp 24h conversation-window rule.
 *
 * Meta rejects free-text messages sent more than 24h after the customer's
 * last inbound message. The effective boundary is the literal 24h window
 * minus a configurable safety margin, absorbing clock skew and network
 * latency on the send path (design.md D3).
 *
 * Null semantics are deliberately asymmetric (design.md D8):
 *   - Sending:  a null `$lastInboundMessageAt` is treated as fail closed —
 *               never send free text we cannot prove is inside the window.
 *   - Closing:  a null `$lastInboundMessageAt` is treated as fail open —
 *               never auto-close a ticket with no known inbound message
 *               (mass-close mitigation for pre-existing tickets).
 */
final class PoliticaDeVentana
{
    public const WINDOW_HOURS = 24;

    public function __construct(private readonly int $safetyMarginMinutes = 0)
    {
    }

    /** Sending: null => false (fail closed). */
    public function isOpenForSending(?\DateTimeImmutable $lastInboundMessageAt, \DateTimeImmutable $now): bool
    {
        if ($lastInboundMessageAt === null) {
            return false;
        }

        return $now < $this->expiresAt($lastInboundMessageAt);
    }

    /** Expiry-closing: null => false (fail open — do not close tickets with no known inbound). */
    public function isExpiredForClosing(?\DateTimeImmutable $lastInboundMessageAt, \DateTimeImmutable $now): bool
    {
        if ($lastInboundMessageAt === null) {
            return false;
        }

        return $now >= $this->expiresAt($lastInboundMessageAt);
    }

    /** Effective deadline = lastInbound + 24h - safetyMargin. */
    public function expiresAt(\DateTimeImmutable $lastInboundMessageAt): \DateTimeImmutable
    {
        return $lastInboundMessageAt
            ->modify(sprintf('+%d hours', self::WINDOW_HOURS))
            ->modify(sprintf('-%d minutes', $this->safetyMarginMinutes));
    }
}
