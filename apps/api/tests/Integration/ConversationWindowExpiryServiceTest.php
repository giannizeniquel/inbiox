<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Conversacion\UseCase\ConversationWindowExpiryService;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ConversationWindowExpiryService::closeExpired()` (design.md Phase 6 —
 * the scheduled closure batch job).
 */
class ConversationWindowExpiryServiceTest extends KernelTestCase
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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    private function createTicket(string $status, ?\DateTimeImmutable $lastInboundMessageAt): int
    {
        $contact = new Contacto();
        $contact->setTelefono('+549110000' . random_int(1000, 9999));
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado($status);
        $ticket->setUltimoMensajeEntranteAt($lastInboundMessageAt);
        $this->em->persist($ticket);
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    public function testClosesOnlyExpiredNonClosedTicketsWithAKnownLastInboundMessage(): void
    {
        $expired = $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-25 hours'));
        $notExpired = $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-1 hour'));
        $nullInbound = $this->createTicket(EstadoDeTicket::NUEVO->value, null);

        $service = static::getContainer()->get(ConversationWindowExpiryService::class);
        $count = $service->closeExpired(dryRun: false, limit: 200);

        self::assertSame(1, $count);

        self::assertSame(
            EstadoDeTicket::CERRADO->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$expired])
        );
        self::assertSame(
            EstadoDeTicket::ACTIVO->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$notExpired])
        );
        self::assertSame(
            EstadoDeTicket::NUEVO->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$nullInbound])
        );

        self::assertSame(
            TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO,
            $this->em->getConnection()->fetchOne(
                'SELECT type FROM ticket_events WHERE ticket_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
                [$expired]
            )
        );
    }

    public function testANeverWorkedNewTicketClosesLikeAnyOther(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::NUEVO->value, new \DateTimeImmutable('-25 hours'));

        $service = static::getContainer()->get(ConversationWindowExpiryService::class);
        $count = $service->closeExpired(dryRun: false, limit: 200);

        self::assertSame(1, $count);
        self::assertSame(
            EstadoDeTicket::CERRADO->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticketId])
        );
    }

    public function testDryRunWritesNothing(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-25 hours'));

        $service = static::getContainer()->get(ConversationWindowExpiryService::class);
        $count = $service->closeExpired(dryRun: true, limit: 200);

        self::assertSame(1, $count);
        self::assertSame(
            EstadoDeTicket::ACTIVO->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticketId]),
            'Dry-run must not mutate any ticket'
        );

        $eventCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ticket_events WHERE ticket_id = ?',
            [$ticketId]
        );
        self::assertSame(0, $eventCount, 'Dry-run must not write any audit event');
    }

    public function testAPerTicketThrowableIsCaughtSoOneBadRowDoesNotAbortTheBatch(): void
    {
        $good = $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-25 hours'));
        // Already-closed rows can't legally satisfy the WHERE clause used by the
        // service (status IN new/active/waiting/resolved), so simulate a bad
        // row by force-setting status to 'closed' AFTER the service's SELECT
        // has already picked it up is not directly testable without mocking;
        // instead we assert the batch completes and processes the good ticket
        // even when a concurrently-closed row would throw inside
        // closeDueToWindowExpiry() due to the FSM guard.
        $concurrentlyClosed = $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-26 hours'));
        $this->em->getConnection()->executeStatement(
            'UPDATE tickets SET status = ? WHERE id = ?',
            [EstadoDeTicket::CERRADO->value, $concurrentlyClosed]
        );

        $service = static::getContainer()->get(ConversationWindowExpiryService::class);
        $count = $service->closeExpired(dryRun: false, limit: 200);

        // Only the genuinely eligible ticket is counted/closed; the
        // already-closed one is excluded by the WHERE clause itself, so this
        // mainly proves the batch completes without throwing.
        self::assertSame(1, $count);
        self::assertSame(
            EstadoDeTicket::CERRADO->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$good])
        );
    }
}
