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
        self::assertTrue($this->fsm->canTransition(EstadoDeTicket::NEW->value, EstadoDeTicket::CLOSED->value));
    }

    public function testActiveToClosedIsNowValid(): void
    {
        self::assertTrue($this->fsm->canTransition(EstadoDeTicket::ACTIVE->value, EstadoDeTicket::CLOSED->value));
    }

    public function testWaitingToClosedIsNowValid(): void
    {
        self::assertTrue($this->fsm->canTransition(EstadoDeTicket::WAITING->value, EstadoDeTicket::CLOSED->value));
    }

    public function testResolvedToClosedRemainsValid(): void
    {
        self::assertTrue($this->fsm->canTransition(EstadoDeTicket::RESOLVED->value, EstadoDeTicket::CLOSED->value));
    }

    /**
     * @dataProvider closedTargetProvider
     */
    public function testEveryClosedToAnythingTransitionIsInvalid(string $target): void
    {
        self::assertFalse($this->fsm->canTransition(EstadoDeTicket::CLOSED->value, $target));
    }

    public static function closedTargetProvider(): array
    {
        return [
            'closed -> new' => [EstadoDeTicket::NEW->value],
            'closed -> active' => [EstadoDeTicket::ACTIVE->value],
            'closed -> waiting' => [EstadoDeTicket::WAITING->value],
            'closed -> resolved' => [EstadoDeTicket::RESOLVED->value],
            'closed -> closed' => [EstadoDeTicket::CLOSED->value],
        ];
    }

    public function testClosedIsStillTerminal(): void
    {
        self::assertTrue($this->fsm->isTerminal(EstadoDeTicket::CLOSED->value));
    }

    public function testNonClosedStatusesAreNotTerminal(): void
    {
        self::assertFalse($this->fsm->isTerminal(EstadoDeTicket::NEW->value));
        self::assertFalse($this->fsm->isTerminal(EstadoDeTicket::ACTIVE->value));
        self::assertFalse($this->fsm->isTerminal(EstadoDeTicket::WAITING->value));
        self::assertFalse($this->fsm->isTerminal(EstadoDeTicket::RESOLVED->value));
    }

    public function testExistingActiveToWaitingTransitionUnaffected(): void
    {
        self::assertTrue($this->fsm->canTransition(EstadoDeTicket::ACTIVE->value, EstadoDeTicket::WAITING->value));
    }
}
