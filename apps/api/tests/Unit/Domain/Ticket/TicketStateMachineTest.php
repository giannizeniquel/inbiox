<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ticket;

use App\Ticket\TicketStateMachine;
use App\Ticket\TicketStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TicketStateMachine — WhatsApp 24h conversation-window
 * compliance (design.md D2: widen the closing side of the FSM).
 *
 * `new|active|waiting → closed` must become valid (Option A: never routed
 * through `resolved`, so resolution metrics stay uncontaminated). Every
 * `closed → *` transition must remain invalid — `closed` stays terminal.
 */
class TicketStateMachineTest extends TestCase
{
    private TicketStateMachine $fsm;

    protected function setUp(): void
    {
        $this->fsm = new TicketStateMachine();
    }

    public function testNewToClosedIsNowValid(): void
    {
        self::assertTrue($this->fsm->canTransition(TicketStatus::NEW->value, TicketStatus::CLOSED->value));
    }

    public function testActiveToClosedIsNowValid(): void
    {
        self::assertTrue($this->fsm->canTransition(TicketStatus::ACTIVE->value, TicketStatus::CLOSED->value));
    }

    public function testWaitingToClosedIsNowValid(): void
    {
        self::assertTrue($this->fsm->canTransition(TicketStatus::WAITING->value, TicketStatus::CLOSED->value));
    }

    public function testResolvedToClosedRemainsValid(): void
    {
        self::assertTrue($this->fsm->canTransition(TicketStatus::RESOLVED->value, TicketStatus::CLOSED->value));
    }

    /**
     * @dataProvider closedTargetProvider
     */
    public function testEveryClosedToAnythingTransitionIsInvalid(string $target): void
    {
        self::assertFalse($this->fsm->canTransition(TicketStatus::CLOSED->value, $target));
    }

    public static function closedTargetProvider(): array
    {
        return [
            'closed -> new' => [TicketStatus::NEW->value],
            'closed -> active' => [TicketStatus::ACTIVE->value],
            'closed -> waiting' => [TicketStatus::WAITING->value],
            'closed -> resolved' => [TicketStatus::RESOLVED->value],
            'closed -> closed' => [TicketStatus::CLOSED->value],
        ];
    }

    public function testClosedIsStillTerminal(): void
    {
        self::assertTrue($this->fsm->isTerminal(TicketStatus::CLOSED->value));
    }

    public function testNonClosedStatusesAreNotTerminal(): void
    {
        self::assertFalse($this->fsm->isTerminal(TicketStatus::NEW->value));
        self::assertFalse($this->fsm->isTerminal(TicketStatus::ACTIVE->value));
        self::assertFalse($this->fsm->isTerminal(TicketStatus::WAITING->value));
        self::assertFalse($this->fsm->isTerminal(TicketStatus::RESOLVED->value));
    }

    public function testExistingActiveToWaitingTransitionUnaffected(): void
    {
        self::assertTrue($this->fsm->canTransition(TicketStatus::ACTIVE->value, TicketStatus::WAITING->value));
    }
}
