<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ticket;

use App\Ticket\MaquinaDeEstadosDeTicket;
use App\Ticket\EstadoDeTicket;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MaquinaDeEstadosDeTicket — WhatsApp 24h conversation-window
 * compliance (design.md D2: widen the closing side of the FSM).
 *
 * `new|active|waiting → closed` must become valid (Option A: never routed
 * through `resolved`, so resolution metrics stay uncontaminated). Every
 * `closed → *` transition must remain invalid — `closed` stays terminal.
 */
class TicketStateMachineTest extends TestCase
{
    private MaquinaDeEstadosDeTicket $fsm;

    protected function setUp(): void
    {
        $this->fsm = new MaquinaDeEstadosDeTicket();
    }

    public function testNewToClosedIsNowValid(): void
    {
        self::assertTrue($this->fsm->puedeTransicionar(EstadoDeTicket::NUEVO->value, EstadoDeTicket::CERRADO->value));
    }

    public function testActiveToClosedIsNowValid(): void
    {
        self::assertTrue($this->fsm->puedeTransicionar(EstadoDeTicket::ACTIVO->value, EstadoDeTicket::CERRADO->value));
    }

    public function testWaitingToClosedIsNowValid(): void
    {
        self::assertTrue($this->fsm->puedeTransicionar(EstadoDeTicket::EN_ESPERA->value, EstadoDeTicket::CERRADO->value));
    }

    public function testResolvedToClosedRemainsValid(): void
    {
        self::assertTrue($this->fsm->puedeTransicionar(EstadoDeTicket::RESUELTO->value, EstadoDeTicket::CERRADO->value));
    }

    /**
     * @dataProvider closedTargetProvider
     */
    public function testEveryClosedToAnythingTransitionIsInvalid(string $target): void
    {
        self::assertFalse($this->fsm->puedeTransicionar(EstadoDeTicket::CERRADO->value, $target));
    }

    public static function closedTargetProvider(): array
    {
        return [
            'closed -> new' => [EstadoDeTicket::NUEVO->value],
            'closed -> active' => [EstadoDeTicket::ACTIVO->value],
            'closed -> waiting' => [EstadoDeTicket::EN_ESPERA->value],
            'closed -> resolved' => [EstadoDeTicket::RESUELTO->value],
            'closed -> closed' => [EstadoDeTicket::CERRADO->value],
        ];
    }

    public function testClosedIsStillTerminal(): void
    {
        self::assertTrue($this->fsm->isTerminal(EstadoDeTicket::CERRADO->value));
    }

    public function testNonClosedStatusesAreNotTerminal(): void
    {
        self::assertFalse($this->fsm->isTerminal(EstadoDeTicket::NUEVO->value));
        self::assertFalse($this->fsm->isTerminal(EstadoDeTicket::ACTIVO->value));
        self::assertFalse($this->fsm->isTerminal(EstadoDeTicket::EN_ESPERA->value));
        self::assertFalse($this->fsm->isTerminal(EstadoDeTicket::RESUELTO->value));
    }

    public function testExistingActiveToWaitingTransitionUnaffected(): void
    {
        self::assertTrue($this->fsm->puedeTransicionar(EstadoDeTicket::ACTIVO->value, EstadoDeTicket::EN_ESPERA->value));
    }
}
