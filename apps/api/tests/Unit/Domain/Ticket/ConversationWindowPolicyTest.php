<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ticket;

use App\Conversacion\PoliticaDeVentana;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PoliticaDeVentana — WhatsApp 24h conversation-window
 * compliance (design.md D3, D8).
 *
 * Boundary table covers exactly-24h and 24h ± safety margin, plus the
 * asymmetric null semantics required by D8:
 *   - Sending:  null lastInboundMessageAt => fail closed (never open).
 *   - Closing:  null lastInboundMessageAt => fail open (never expired/closeable).
 */
class ConversationWindowPolicyTest extends TestCase
{
    public function testIsOpenForSendingIsTrueWellInsideTheWindow(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 5);
        $now = new \DateTimeImmutable('2026-01-02 12:00:00');
        $lastInbound = $now->modify('-1 hour');

        self::assertTrue($policy->isAbiertaParaEnviar($lastInbound, $now));
    }

    public function testIsOpenForSendingIsFalseExactlyAtTheLiteral24hBoundaryWithNoMargin(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 0);
        $now = new \DateTimeImmutable('2026-01-02 12:00:00');
        $lastInbound = $now->modify('-24 hours');

        // Elapsed time equals the window exactly: treated as no longer open.
        self::assertFalse($policy->isAbiertaParaEnviar($lastInbound, $now));
    }

    public function testIsOpenForSendingIsTrueOneMinuteBeforeTheEffectiveBoundary(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 5);
        $lastInbound = new \DateTimeImmutable('2026-01-01 12:00:00');
        // Effective boundary = lastInbound + 24h - 5min = 2026-01-02 11:55:00.
        $now = $lastInbound->modify('+23 hours +53 minutes');

        self::assertTrue($policy->isAbiertaParaEnviar($lastInbound, $now));
    }

    public function testIsOpenForSendingIsFalseAfterTheEffectiveBoundaryWithinTheSafetyMargin(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 5);
        $lastInbound = new \DateTimeImmutable('2026-01-01 12:00:00');
        // 23h56m elapsed — past the 23h55m effective boundary but still
        // inside the literal 24h window (spec.md "Send rejected within the margin").
        $now = $lastInbound->modify('+23 hours +56 minutes');

        self::assertFalse($policy->isAbiertaParaEnviar($lastInbound, $now));
    }

    public function testIsOpenForSendingFailsClosedWhenLastInboundMessageAtIsNull(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 5);

        self::assertFalse($policy->isAbiertaParaEnviar(null, new \DateTimeImmutable()));
    }

    public function testIsExpiredForClosingIsFalseWellInsideTheWindow(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 5);
        $now = new \DateTimeImmutable('2026-01-02 12:00:00');
        $lastInbound = $now->modify('-1 hour');

        self::assertFalse($policy->isVencidaParaCerrar($lastInbound, $now));
    }

    public function testIsExpiredForClosingIsTrueExactlyAtTheLiteral24hBoundaryWithNoMargin(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 0);
        $now = new \DateTimeImmutable('2026-01-02 12:00:00');
        $lastInbound = $now->modify('-24 hours');

        self::assertTrue($policy->isVencidaParaCerrar($lastInbound, $now));
    }

    public function testIsExpiredForClosingIsTrueAfterTheEffectiveBoundary(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 5);
        $lastInbound = new \DateTimeImmutable('2026-01-01 12:00:00');
        $now = $lastInbound->modify('+23 hours +56 minutes');

        self::assertTrue($policy->isVencidaParaCerrar($lastInbound, $now));
    }

    public function testIsExpiredForClosingFailsOpenWhenLastInboundMessageAtIsNull(): void
    {
        // D8: null must NEVER be treated as expired — mass-close mitigation
        // for pre-existing tickets with no recorded inbound message.
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 5);

        self::assertFalse($policy->isVencidaParaCerrar(null, new \DateTimeImmutable()));
    }

    public function testExpiresAtSubtractsTheSafetyMarginFromTheLiteral24hBoundary(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 5);
        $lastInbound = new \DateTimeImmutable('2026-01-01 12:00:00');

        $expected = new \DateTimeImmutable('2026-01-02 11:55:00');

        self::assertEquals($expected, $policy->getVenceAt($lastInbound));
    }

    public function testExpiresAtWithZeroMarginEqualsTheLiteral24hBoundary(): void
    {
        $policy = new PoliticaDeVentana(safetyMarginMinutes: 0);
        $lastInbound = new \DateTimeImmutable('2026-01-01 12:00:00');

        $expected = new \DateTimeImmutable('2026-01-02 12:00:00');

        self::assertEquals($expected, $policy->getVenceAt($lastInbound));
    }
}
