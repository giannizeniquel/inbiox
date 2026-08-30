<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\UseCase\TransitionTicketStatusUseCase;
use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use App\Domain\Event\TicketEventType;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for `TransitionTicketStatusUseCase`'s two new WhatsApp
 * 24h conversation-window methods: `closeDueToWindowExpiry()` (Phase 2) and
 * `reopenDueToInboundMessage()` (Phase 3).
 *
 * `close()`/`execute()` must remain untouched — see the regression-guard
 * test at the bottom of this class.
 */
class TransitionTicketStatusUseCaseTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    private function createTicket(string $status): int
    {
        $contact = new Contact();
        $contact->setPhone('+5491100000100');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus($status);
        $this->em->persist($ticket);
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    private function latestEventType(int $ticketId): ?string
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT type FROM ticket_events WHERE ticket_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$ticketId]
        ) ?: null;
    }

    // ── 2.1 / 2.2: closeDueToWindowExpiry() ──

    /**
     * @dataProvider closableStatusProvider
     */
    public function testCloseDueToWindowExpiryTransitionsToClosedAndWritesAuditEvent(string $fromStatus): void
    {
        $ticketId = $this->createTicket($fromStatus);

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);
        $result = $useCase->closeDueToWindowExpiry($ticketId);

        self::assertSame(TicketStatus::CLOSED->value, $result['new_status']);

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticketId]);
        self::assertSame(TicketStatus::CLOSED->value, $status);

        self::assertSame(TicketEventType::WINDOW_EXPIRED_CLOSED, $this->latestEventType($ticketId));
    }

    public static function closableStatusProvider(): array
    {
        return [
            'new' => [TicketStatus::NEW->value],
            'active' => [TicketStatus::ACTIVE->value],
            'waiting' => [TicketStatus::WAITING->value],
        ];
    }

    public function testCloseDueToWindowExpiryRejectsAnAlreadyClosedTicket(): void
    {
        $ticketId = $this->createTicket(TicketStatus::CLOSED->value);

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);

        $this->expectException(\App\Domain\Ticket\InvalidTransitionException::class);
        $useCase->closeDueToWindowExpiry($ticketId);
    }

    // ── 2.3 / 2.4: regression guard on close() ──

    public function testCloseStillRejectsNonResolvedTickets(): void
    {
        $ticketId = $this->createTicket(TicketStatus::ACTIVE->value);

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only resolved tickets can be closed');
        $useCase->close($ticketId);
    }

    public function testCloseStillSucceedsForResolvedTickets(): void
    {
        $ticketId = $this->createTicket(TicketStatus::RESOLVED->value);

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);
        $result = $useCase->close($ticketId);

        self::assertSame(TicketStatus::CLOSED->value, $result['new_status']);
    }

    // ── 3.3 / 3.4: reopenDueToInboundMessage() ──

    private function addTicketEvent(int $ticketId, string $type): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, ?, ?, NOW())',
            [$ticketId, $type, 'system']
        );
    }

    public function testReopenDueToInboundMessageReopensAWindowExpiredClosedTicket(): void
    {
        $ticketId = $this->createTicket(TicketStatus::CLOSED->value);
        $this->addTicketEvent($ticketId, TicketEventType::WINDOW_EXPIRED_CLOSED);

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);
        $result = $useCase->reopenDueToInboundMessage($ticketId);

        self::assertSame(TicketStatus::CLOSED->value, $result['previous_status']);
        self::assertSame(TicketStatus::ACTIVE->value, $result['new_status']);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, closed_at FROM tickets WHERE id = ?',
            [$ticketId]
        );
        self::assertSame(TicketStatus::ACTIVE->value, $row['status']);
        self::assertNull($row['closed_at']);

        self::assertSame(TicketEventType::WINDOW_REOPENED, $this->latestEventType($ticketId));
    }

    /**
     * This is the structural proof that reopening never routes through the
     * shared FSM table: `closed → active` is NOT in `TicketStateMachine::
     * TRANSITIONS` (closed stays terminal there), yet this method succeeds
     * by design, because it has its own independent guard (design.md D1).
     */
    public function testReopenSucceedsEvenThoughClosedToActiveIsNotInTheSharedFsmTable(): void
    {
        $fsm = new \App\Domain\Ticket\TicketStateMachine();
        self::assertFalse($fsm->canTransition(TicketStatus::CLOSED->value, TicketStatus::ACTIVE->value));

        $ticketId = $this->createTicket(TicketStatus::CLOSED->value);
        $this->addTicketEvent($ticketId, TicketEventType::WINDOW_EXPIRED_CLOSED);

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);
        $result = $useCase->reopenDueToInboundMessage($ticketId);

        self::assertSame(TicketStatus::ACTIVE->value, $result['new_status']);
    }

    public function testReopenDueToInboundMessageRejectsANonClosedTicket(): void
    {
        $ticketId = $this->createTicket(TicketStatus::ACTIVE->value);
        $this->addTicketEvent($ticketId, TicketEventType::WINDOW_EXPIRED_CLOSED);

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);

        $this->expectException(\InvalidArgumentException::class);
        $useCase->reopenDueToInboundMessage($ticketId);
    }

    public function testReopenDueToInboundMessageRejectsAClosedTicketWithADifferentLatestEventType(): void
    {
        $ticketId = $this->createTicket(TicketStatus::CLOSED->value);
        $this->addTicketEvent($ticketId, 'auto_closed');

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);

        $this->expectException(\InvalidArgumentException::class);
        $useCase->reopenDueToInboundMessage($ticketId);
    }

    public function testReopenDueToInboundMessageRejectsAClosedTicketWithNoEvents(): void
    {
        $ticketId = $this->createTicket(TicketStatus::CLOSED->value);

        $useCase = static::getContainer()->get(TransitionTicketStatusUseCase::class);

        $this->expectException(\InvalidArgumentException::class);
        $useCase->reopenDueToInboundMessage($ticketId);
    }
}
