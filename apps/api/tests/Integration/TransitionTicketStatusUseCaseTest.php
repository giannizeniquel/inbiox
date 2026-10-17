<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ticket\UseCase\ResolverTicketUseCase;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use App\Agente\Agent;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for `CambiarEstadoDeTicketUseCase`'s two new WhatsApp
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

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $result = $useCase->closeDueToWindowExpiry($ticketId);

        self::assertSame(EstadoDeTicket::CLOSED->value, $result['new_status']);

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticketId]);
        self::assertSame(EstadoDeTicket::CLOSED->value, $status);

        self::assertSame(TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED, $this->latestEventType($ticketId));
    }

    public static function closableStatusProvider(): array
    {
        return [
            'new' => [EstadoDeTicket::NEW->value],
            'active' => [EstadoDeTicket::ACTIVE->value],
            'waiting' => [EstadoDeTicket::WAITING->value],
        ];
    }

    public function testCloseDueToWindowExpiryRejectsAnAlreadyClosedTicket(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::CLOSED->value);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);

        $this->expectException(\App\Ticket\TransicionInvalidaException::class);
        $useCase->closeDueToWindowExpiry($ticketId);
    }

    // ── 2.3 / 2.4: regression guard on close() ──

    public function testCloseStillRejectsNonResolvedTickets(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVE->value);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only resolved tickets can be closed');
        $useCase->close($ticketId);
    }

    public function testCloseStillSucceedsForResolvedTickets(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::RESOLVED->value);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $result = $useCase->close($ticketId);

        self::assertSame(EstadoDeTicket::CLOSED->value, $result['new_status']);
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
        $ticketId = $this->createTicket(EstadoDeTicket::CLOSED->value);
        $this->addTicketEvent($ticketId, TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $result = $useCase->reopenDueToInboundMessage($ticketId);

        self::assertSame(EstadoDeTicket::CLOSED->value, $result['previous_status']);
        self::assertSame(EstadoDeTicket::ACTIVE->value, $result['new_status']);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, closed_at FROM tickets WHERE id = ?',
            [$ticketId]
        );
        self::assertSame(EstadoDeTicket::ACTIVE->value, $row['status']);
        self::assertNull($row['closed_at']);

        self::assertSame(TipoDeEventoDeTicket::WINDOW_REOPENED, $this->latestEventType($ticketId));
    }

    /**
     * This is the structural proof that reopening never routes through the
     * shared FSM table: `closed → active` is NOT in `MaquinaDeEstadosDeTicket::
     * TRANSITIONS` (closed stays terminal there), yet this method succeeds
     * by design, because it has its own independent guard (design.md D1).
     */
    public function testReopenSucceedsEvenThoughClosedToActiveIsNotInTheSharedFsmTable(): void
    {
        $fsm = new \App\Ticket\MaquinaDeEstadosDeTicket();
        self::assertFalse($fsm->canTransition(EstadoDeTicket::CLOSED->value, EstadoDeTicket::ACTIVE->value));

        $ticketId = $this->createTicket(EstadoDeTicket::CLOSED->value);
        $this->addTicketEvent($ticketId, TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $result = $useCase->reopenDueToInboundMessage($ticketId);

        self::assertSame(EstadoDeTicket::ACTIVE->value, $result['new_status']);
    }

    public function testReopenDueToInboundMessageRejectsANonClosedTicket(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVE->value);
        $this->addTicketEvent($ticketId, TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);

        $this->expectException(\InvalidArgumentException::class);
        $useCase->reopenDueToInboundMessage($ticketId);
    }

    public function testReopenDueToInboundMessageRejectsAClosedTicketWithADifferentLatestEventType(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::CLOSED->value);
        $this->addTicketEvent($ticketId, 'auto_closed');

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);

        $this->expectException(\InvalidArgumentException::class);
        $useCase->reopenDueToInboundMessage($ticketId);
    }

    public function testReopenDueToInboundMessageRejectsAClosedTicketWithNoEvents(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::CLOSED->value);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);

        $this->expectException(\InvalidArgumentException::class);
        $useCase->reopenDueToInboundMessage($ticketId);
    }

    // ── Phase 3: actor threading (design.md D5/D4/D7) ──

    private function createAgent(string $name): int
    {
        $agent = new Agent($name);
        $this->em->persist($agent);
        $this->em->flush();

        $id = $agent->getId();
        \assert($id !== null);

        return $id;
    }

    private function createTicketWithOwner(string $status, int $agentId): int
    {
        $contact = new Contact();
        $contact->setPhone('+5491100000200');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus($status);
        $ticket->setAssignedAgentId($agentId);
        $this->em->persist($ticket);
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    private function latestAssignmentAssignedBy(int $ticketId): ?string
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT assigned_by FROM assignments WHERE ticket_id = ? ORDER BY id DESC LIMIT 1',
            [$ticketId]
        ) ?: null;
    }

    public function testExecuteWithARealActorRecordsAssignedByAsThatActorNotSystem(): void
    {
        $agentId = $this->createAgent('Actor Test Agent');
        $ticketId = $this->createTicketWithOwner(EstadoDeTicket::ACTIVE->value, $agentId);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $useCase->execute($ticketId, EstadoDeTicket::RESOLVED->value, true, true, 'u:9');

        self::assertSame('u:9', $this->latestAssignmentAssignedBy($ticketId));
    }

    public function testCloseWithARealActorRecordsAssignedByAsThatActorNotSystem(): void
    {
        $agentId = $this->createAgent('Actor Test Agent Close');
        $ticketId = $this->createTicketWithOwner(EstadoDeTicket::RESOLVED->value, $agentId);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $useCase->close($ticketId, 'u:11');

        self::assertSame('u:11', $this->latestAssignmentAssignedBy($ticketId));
    }

    public function testResolveForwardsTheActorThroughToTheAssignment(): void
    {
        $agentId = $this->createAgent('Actor Test Agent Resolve');
        $ticketId = $this->createTicketWithOwner(EstadoDeTicket::ACTIVE->value, $agentId);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $useCase->resolve($ticketId, 'u:13');

        self::assertSame('u:13', $this->latestAssignmentAssignedBy($ticketId));
    }

    public function testResolveTicketUseCaseForwardsTheActor(): void
    {
        $agentId = $this->createAgent('Actor Test Agent ResolveUseCase');
        $ticketId = $this->createTicketWithOwner(EstadoDeTicket::ACTIVE->value, $agentId);

        $resolveUseCase = static::getContainer()->get(ResolverTicketUseCase::class);
        $resolveUseCase->execute($ticketId, 'u:17');

        self::assertSame('u:17', $this->latestAssignmentAssignedBy($ticketId));
    }

    public function testExecuteWithoutAnExplicitActorStillDefaultsToSystem(): void
    {
        $agentId = $this->createAgent('Default System Agent');
        $ticketId = $this->createTicketWithOwner(EstadoDeTicket::ACTIVE->value, $agentId);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $useCase->execute($ticketId, EstadoDeTicket::RESOLVED->value, true, true);

        self::assertSame('system', $this->latestAssignmentAssignedBy($ticketId));
    }

    public function testCloseWithoutAnExplicitActorStillDefaultsToSystem(): void
    {
        $agentId = $this->createAgent('Default System Agent Close');
        $ticketId = $this->createTicketWithOwner(EstadoDeTicket::RESOLVED->value, $agentId);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $useCase->close($ticketId);

        self::assertSame('system', $this->latestAssignmentAssignedBy($ticketId));
    }

    public function testExecuteReturnsThePreTransitionOwnerAsPreviousAssignedAgentId(): void
    {
        $agentId = $this->createAgent('Prev Owner Agent');
        $ticketId = $this->createTicketWithOwner(EstadoDeTicket::ACTIVE->value, $agentId);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $result = $useCase->execute($ticketId, EstadoDeTicket::RESOLVED->value, true, true, 'u:9');

        self::assertSame($agentId, $result['previous_assigned_agent_id']);
    }

    public function testExecuteReturnsNullPreviousAssignedAgentIdWhenUnowned(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVE->value);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $result = $useCase->execute($ticketId, EstadoDeTicket::RESOLVED->value, true, true, 'u:9');

        self::assertNull($result['previous_assigned_agent_id']);
    }

    public function testCloseReturnsThePreTransitionOwnerAsPreviousAssignedAgentId(): void
    {
        $agentId = $this->createAgent('Prev Owner Agent Close');
        $ticketId = $this->createTicketWithOwner(EstadoDeTicket::RESOLVED->value, $agentId);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $result = $useCase->close($ticketId, 'u:11');

        self::assertSame($agentId, $result['previous_assigned_agent_id']);
    }

    /**
     * design.md D7: `execute()` lands on `closed` when a caller transitions
     * directly there — the agent's primary own-ticket close path once D3's
     * `/close` finding holds. `closed_at` must be set, matching `close()`'s
     * own behavior.
     */
    public function testExecuteSetsClosedAtWhenLandingOnClosed(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVE->value);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $useCase->execute($ticketId, EstadoDeTicket::CLOSED->value);

        $closedAt = $this->em->getConnection()->fetchOne('SELECT closed_at FROM tickets WHERE id = ?', [$ticketId]);
        self::assertNotNull($closedAt);
    }

    // ── Phase 3.6: regression — automatic callers unaffected by the new optional $actor ──

    public function testAutoReopenRuleStillCompilesAndBehavesUnchangedWithTheNewOptionalActorParameter(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::RESOLVED->value);

        $useCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        // Mirrors AutoReopenRule::execute()'s exact 4-positional-arg call site.
        $result = $useCase->execute($ticketId, EstadoDeTicket::WAITING->value, false, false);

        self::assertSame(EstadoDeTicket::WAITING->value, $result['new_status']);
    }
}
