<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Ticket\Repository\TicketRepository;
use App\Ticket\Ticket;
use App\Ticket\EventoDeTicket;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\EstadoDeTicket;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SQL that phase 3c-1 moved out of the ticket use cases into
 * TicketRepository. Each test builds its own rows and deletes them afterwards.
 */
class TicketRepositorySqlTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private TicketRepository $repo;
    private Contact $contact;
    /** @var int[] */
    private array $ticketIds = [];
    /** @var int[] */
    private array $conversationIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->repo = static::getContainer()->get(TicketRepository::class);

        $this->contact = new Contact();
        $this->contact->setPhone('+5491100009901');
        $this->em->persist($this->contact);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        if ($this->ticketIds !== []) {
            $this->conn->executeStatement('DELETE FROM ticket_events WHERE ticket_id IN (?)', [$this->ticketIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
            $this->conn->executeStatement('DELETE FROM tickets WHERE id IN (?)', [$this->ticketIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        if ($this->conversationIds !== []) {
            $this->conn->executeStatement('DELETE FROM conversations WHERE id IN (?)', [$this->conversationIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        $this->conn->executeStatement('DELETE FROM contacts WHERE id = ?', [$this->contact->getId()]);
        parent::tearDown();
    }

    private function createTicket(string $status, ?string $phoneNumberId = null, ?string $lastInboundAt = null): int
    {
        $conversation = new Conversation();
        $conversation->setContact($this->contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($this->contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus($status);
        $ticket->setPhoneNumberId($phoneNumberId);
        if ($lastInboundAt !== null) {
            $ticket->setLastInboundMessageAt(new \DateTimeImmutable($lastInboundAt));
        }
        $this->em->persist($ticket);
        $this->em->flush();

        $this->conversationIds[] = (int) $conversation->getId();
        $this->ticketIds[] = (int) $ticket->getId();

        return (int) $ticket->getId();
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->conn->fetchAssociative('SELECT * FROM tickets WHERE id = ?', [$id]);
    }

    public function testFindRowByIdForUpdateReturnsTheRawRow(): void
    {
        $id = $this->createTicket(EstadoDeTicket::ACTIVE->value, 'PN-1');

        $row = $this->repo->findRowByIdForUpdate($id);

        self::assertIsArray($row);
        self::assertSame($id, (int) $row['id']);
        self::assertSame('active', $row['status']);
        self::assertSame('PN-1', $row['phone_number_id']);
        self::assertArrayHasKey('assigned_agent_id', $row);
    }

    public function testFindRowByIdForUpdateReturnsFalseForAnUnknownTicket(): void
    {
        self::assertFalse($this->repo->findRowByIdForUpdate(999999999));
    }

    public function testFindRowByIdForUpdateHoldsARowLockInsideTheCallersTransaction(): void
    {
        $id = $this->createTicket(EstadoDeTicket::ACTIVE->value);
        $other = DriverManager::getConnection($this->conn->getParams());

        // Without the lock, another connection locks the row at once.
        $other->beginTransaction();
        $other->executeQuery('SELECT id FROM tickets WHERE id = ? FOR UPDATE NOWAIT', [$id]);
        $other->rollBack();

        $this->conn->beginTransaction();
        $this->repo->findRowByIdForUpdate($id);

        try {
            $this->expectException(\Doctrine\DBAL\Exception::class);
            $other->executeQuery('SELECT id FROM tickets WHERE id = ? FOR UPDATE NOWAIT', [$id]);
        } finally {
            $other->close();
            $this->conn->rollBack();
        }
    }

    public function testUpdateStatusAfterTransitionSetsOnlyTheRequestedTimestamps(): void
    {
        $id = $this->createTicket(EstadoDeTicket::NEW->value);
        $this->conn->executeStatement('UPDATE tickets SET updated_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $id]);

        $this->repo->updateStatusAfterTransition($id, EstadoDeTicket::ACTIVE->value, true, false, false);

        $row = $this->row($id);
        self::assertSame('active', $row['status']);
        self::assertNotNull($row['first_response_at']);
        self::assertNull($row['resolved_at']);
        self::assertNull($row['closed_at']);
        self::assertNotSame('2000-01-01 00:00:00', $row['updated_at']);
    }

    public function testUpdateStatusAfterTransitionCanSetResolvedAndClosed(): void
    {
        $resolvedId = $this->createTicket(EstadoDeTicket::ACTIVE->value);
        $closedId = $this->createTicket(EstadoDeTicket::ACTIVE->value);

        $this->repo->updateStatusAfterTransition($resolvedId, EstadoDeTicket::RESOLVED->value, false, true, false);
        $this->repo->updateStatusAfterTransition($closedId, EstadoDeTicket::CLOSED->value, false, false, true);

        $resolved = $this->row($resolvedId);
        self::assertSame('resolved', $resolved['status']);
        self::assertNull($resolved['first_response_at']);
        self::assertNotNull($resolved['resolved_at']);
        self::assertNull($resolved['closed_at']);

        $closed = $this->row($closedId);
        self::assertSame('closed', $closed['status']);
        self::assertNull($closed['resolved_at']);
        self::assertNotNull($closed['closed_at']);
    }

    public function testUpdateStatusAfterTransitionOnlyTouchesTheGivenTicket(): void
    {
        $id = $this->createTicket(EstadoDeTicket::NEW->value);
        $otherId = $this->createTicket(EstadoDeTicket::NEW->value);

        $this->repo->updateStatusAfterTransition($id, EstadoDeTicket::ACTIVE->value, true, true, true);

        $other = $this->row($otherId);
        self::assertSame('new', $other['status']);
        self::assertNull($other['first_response_at']);
    }

    public function testMarkClosedSetsStatusAndClosedAt(): void
    {
        $id = $this->createTicket(EstadoDeTicket::RESOLVED->value);

        $this->repo->markClosed($id, EstadoDeTicket::CLOSED->value);

        $row = $this->row($id);
        self::assertSame('closed', $row['status']);
        self::assertNotNull($row['closed_at']);
    }

    public function testMarkReopenedSetsStatusAndClearsClosedAt(): void
    {
        $id = $this->createTicket(EstadoDeTicket::CLOSED->value);
        $this->conn->executeStatement('UPDATE tickets SET closed_at = NOW() WHERE id = ?', [$id]);

        $this->repo->markReopened($id, EstadoDeTicket::ACTIVE->value);

        $row = $this->row($id);
        self::assertSame('active', $row['status']);
        self::assertNull($row['closed_at']);
    }

    public function testMarkFirstResponseSetsFirstResponseAtWithoutChangingStatus(): void
    {
        $id = $this->createTicket(EstadoDeTicket::WAITING->value);

        $this->repo->markFirstResponse($id);

        $row = $this->row($id);
        self::assertSame('waiting', $row['status']);
        self::assertNotNull($row['first_response_at']);
    }

    public function testFindLatestEventTypeOrdersByCreatedAtThenId(): void
    {
        $id = $this->createTicket(EstadoDeTicket::CLOSED->value);
        self::assertFalse($this->repo->findLatestEventType($id));

        foreach (['tag_added', TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED, 'tag_added'] as $type) {
            $this->em->persist(new EventoDeTicket($id, $type, 'system'));
            $this->em->flush();
        }
        // Same created_at for all three: the highest id wins.
        $this->conn->executeStatement('UPDATE ticket_events SET created_at = ? WHERE ticket_id = ?', ['2026-01-01 00:00:00', $id]);
        self::assertSame('tag_added', $this->repo->findLatestEventType($id));

        // A newer created_at wins over a higher id.
        $firstEventId = $this->conn->fetchOne('SELECT MIN(id) FROM ticket_events WHERE ticket_id = ?', [$id]);
        $this->conn->executeStatement('UPDATE ticket_events SET type = ?, created_at = ? WHERE id = ?', [TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED, '2026-02-01 00:00:00', $firstEventId]);
        self::assertSame(TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED, $this->repo->findLatestEventType($id));
    }

    public function testFindPhoneNumberId(): void
    {
        $withPhone = $this->createTicket(EstadoDeTicket::NEW->value, 'PN-42');
        $withoutPhone = $this->createTicket(EstadoDeTicket::NEW->value);

        self::assertSame('PN-42', $this->repo->findPhoneNumberId($withPhone));
        self::assertNull($this->repo->findPhoneNumberId($withoutPhone));
        self::assertNull($this->repo->findPhoneNumberId(999999999));
    }

    public function testFindIdsWithExpiredWindowFiltersOrdersAndLimits(): void
    {
        $older = $this->createTicket(EstadoDeTicket::ACTIVE->value, null, '1990-01-01 08:00:00');
        $old = $this->createTicket(EstadoDeTicket::RESOLVED->value, null, '1990-01-01 09:00:00');
        $closed = $this->createTicket(EstadoDeTicket::CLOSED->value, null, '1990-01-01 07:00:00');
        $recent = $this->createTicket(EstadoDeTicket::ACTIVE->value, null, '1990-01-03 00:00:00');
        $noInbound = $this->createTicket(EstadoDeTicket::ACTIVE->value);

        $statuses = ['new', 'active', 'waiting', 'resolved'];
        $ids = array_map('intval', $this->repo->findIdsWithExpiredWindow($statuses, '1990-01-02 00:00:00', 100));
        $mine = array_values(array_intersect($ids, [$older, $old, $closed, $recent, $noInbound]));

        self::assertSame([$older, $old], $mine);

        $limited = array_map('intval', $this->repo->findIdsWithExpiredWindow($statuses, '1990-01-02 00:00:00', 1));
        self::assertCount(1, $limited);
        self::assertSame([], $this->repo->findIdsWithExpiredWindow($statuses, '1990-01-02 00:00:00', 0));
    }
}
