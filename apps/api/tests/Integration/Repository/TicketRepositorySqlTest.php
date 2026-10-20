<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
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
    private Contacto $contact;
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

        $this->contact = new Contacto();
        $this->contact->setTelefono('+5491100009901');
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
        $conversation = new Conversacion();
        $conversation->setContacto($this->contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($this->contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado($status);
        $ticket->setPhoneNumberId($phoneNumberId);
        if ($lastInboundAt !== null) {
            $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable($lastInboundAt));
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
        $id = $this->createTicket(EstadoDeTicket::ACTIVO->value, 'PN-1');

        $row = $this->repo->findFilaByIdForUpdate($id);

        self::assertIsArray($row);
        self::assertSame($id, (int) $row['id']);
        self::assertSame('activo', $row['status']);
        self::assertSame('PN-1', $row['phone_number_id']);
        self::assertArrayHasKey('assigned_agent_id', $row);
    }

    public function testFindRowByIdForUpdateReturnsFalseForAnUnknownTicket(): void
    {
        self::assertFalse($this->repo->findFilaByIdForUpdate(999999999));
    }

    public function testFindRowByIdForUpdateHoldsARowLockInsideTheCallersTransaction(): void
    {
        $id = $this->createTicket(EstadoDeTicket::ACTIVO->value);
        $other = DriverManager::getConnection($this->conn->getParams());

        // Without the lock, another connection locks the row at once.
        $other->beginTransaction();
        $other->executeQuery('SELECT id FROM tickets WHERE id = ? FOR UPDATE NOWAIT', [$id]);
        $other->rollBack();

        $this->conn->beginTransaction();
        $this->repo->findFilaByIdForUpdate($id);

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
        $id = $this->createTicket(EstadoDeTicket::NUEVO->value);
        $this->conn->executeStatement('UPDATE tickets SET updated_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $id]);

        $this->repo->updateEstadoTrasTransicion($id, EstadoDeTicket::ACTIVO->value, true, false, false);

        $row = $this->row($id);
        self::assertSame('activo', $row['status']);
        self::assertNotNull($row['first_response_at']);
        self::assertNull($row['resolved_at']);
        self::assertNull($row['closed_at']);
        self::assertNotSame('2000-01-01 00:00:00', $row['updated_at']);
    }

    public function testUpdateStatusAfterTransitionCanSetResolvedAndClosed(): void
    {
        $resolvedId = $this->createTicket(EstadoDeTicket::ACTIVO->value);
        $closedId = $this->createTicket(EstadoDeTicket::ACTIVO->value);

        $this->repo->updateEstadoTrasTransicion($resolvedId, EstadoDeTicket::RESUELTO->value, false, true, false);
        $this->repo->updateEstadoTrasTransicion($closedId, EstadoDeTicket::CERRADO->value, false, false, true);

        $resolved = $this->row($resolvedId);
        self::assertSame('resuelto', $resolved['status']);
        self::assertNull($resolved['first_response_at']);
        self::assertNotNull($resolved['resolved_at']);
        self::assertNull($resolved['closed_at']);

        $closed = $this->row($closedId);
        self::assertSame('cerrado', $closed['status']);
        self::assertNull($closed['resolved_at']);
        self::assertNotNull($closed['closed_at']);
    }

    public function testUpdateStatusAfterTransitionOnlyTouchesTheGivenTicket(): void
    {
        $id = $this->createTicket(EstadoDeTicket::NUEVO->value);
        $otherId = $this->createTicket(EstadoDeTicket::NUEVO->value);

        $this->repo->updateEstadoTrasTransicion($id, EstadoDeTicket::ACTIVO->value, true, true, true);

        $other = $this->row($otherId);
        self::assertSame('nuevo', $other['status']);
        self::assertNull($other['first_response_at']);
    }

    public function testMarkClosedSetsStatusAndClosedAt(): void
    {
        $id = $this->createTicket(EstadoDeTicket::RESUELTO->value);

        $this->repo->marcarCerrado($id, EstadoDeTicket::CERRADO->value);

        $row = $this->row($id);
        self::assertSame('cerrado', $row['status']);
        self::assertNotNull($row['closed_at']);
    }

    public function testMarkReopenedSetsStatusAndClearsClosedAt(): void
    {
        $id = $this->createTicket(EstadoDeTicket::CERRADO->value);
        $this->conn->executeStatement('UPDATE tickets SET closed_at = NOW() WHERE id = ?', [$id]);

        $this->repo->marcarReabierto($id, EstadoDeTicket::ACTIVO->value);

        $row = $this->row($id);
        self::assertSame('activo', $row['status']);
        self::assertNull($row['closed_at']);
    }

    public function testMarkFirstResponseSetsFirstResponseAtWithoutChangingStatus(): void
    {
        $id = $this->createTicket(EstadoDeTicket::EN_ESPERA->value);

        $this->repo->marcarPrimeraRespuesta($id);

        $row = $this->row($id);
        self::assertSame('en_espera', $row['status']);
        self::assertNotNull($row['first_response_at']);
    }

    public function testFindLatestEventTypeOrdersByCreatedAtThenId(): void
    {
        $id = $this->createTicket(EstadoDeTicket::CERRADO->value);
        self::assertFalse($this->repo->findUltimoTipoDeEvento($id));

        foreach (['etiqueta_agregada', TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO, 'etiqueta_agregada'] as $type) {
            $this->em->persist(new EventoDeTicket($id, $type, 'system'));
            $this->em->flush();
        }
        // Same created_at for all three: the highest id wins.
        $this->conn->executeStatement('UPDATE ticket_events SET created_at = ? WHERE ticket_id = ?', ['2026-01-01 00:00:00', $id]);
        self::assertSame('etiqueta_agregada', $this->repo->findUltimoTipoDeEvento($id));

        // A newer created_at wins over a higher id.
        $firstEventId = $this->conn->fetchOne('SELECT MIN(id) FROM ticket_events WHERE ticket_id = ?', [$id]);
        $this->conn->executeStatement('UPDATE ticket_events SET type = ?, created_at = ? WHERE id = ?', [TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO, '2026-02-01 00:00:00', $firstEventId]);
        self::assertSame(TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO, $this->repo->findUltimoTipoDeEvento($id));
    }

    public function testFindPhoneNumberId(): void
    {
        $withPhone = $this->createTicket(EstadoDeTicket::NUEVO->value, 'PN-42');
        $withoutPhone = $this->createTicket(EstadoDeTicket::NUEVO->value);

        self::assertSame('PN-42', $this->repo->findPhoneNumberId($withPhone));
        self::assertNull($this->repo->findPhoneNumberId($withoutPhone));
        self::assertNull($this->repo->findPhoneNumberId(999999999));
    }

    public function testFindIdsWithExpiredWindowFiltersOrdersAndLimits(): void
    {
        $older = $this->createTicket(EstadoDeTicket::ACTIVO->value, null, '1990-01-01 08:00:00');
        $old = $this->createTicket(EstadoDeTicket::RESUELTO->value, null, '1990-01-01 09:00:00');
        $closed = $this->createTicket(EstadoDeTicket::CERRADO->value, null, '1990-01-01 07:00:00');
        $recent = $this->createTicket(EstadoDeTicket::ACTIVO->value, null, '1990-01-03 00:00:00');
        $noInbound = $this->createTicket(EstadoDeTicket::ACTIVO->value);

        $statuses = ['nuevo', 'activo', 'en_espera', 'resuelto'];
        $ids = array_map('intval', $this->repo->findIdsConVentanaVencida($statuses, '1990-01-02 00:00:00', 100));
        $mine = array_values(array_intersect($ids, [$older, $old, $closed, $recent, $noInbound]));

        self::assertSame([$older, $old], $mine);

        $limited = array_map('intval', $this->repo->findIdsConVentanaVencida($statuses, '1990-01-02 00:00:00', 1));
        self::assertCount(1, $limited);
        self::assertSame([], $this->repo->findIdsConVentanaVencida($statuses, '1990-01-02 00:00:00', 0));
    }
}
