<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Ticket\EventoDeTicket;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for `TicketRepository::findWindowExpiredClosedByContact()`
 * (design.md's correlated-subquery contract).
 */
class TicketRepositoryTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?TicketRepository $repo = null;
    private ?Contacto $contact = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(TicketRepository::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');

        $this->contact = new Contacto();
        $this->contact->setTelefono('+5491100000200');
        $this->em->persist($this->contact);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
        $this->repo = null;
        $this->contact = null;
    }

    private function createClosedTicket(?string $phoneNumberId = null): int
    {
        $conversation = new Conversacion();
        $conversation->setContacto($this->contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($this->contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::CERRADO->value);
        if ($phoneNumberId !== null) {
            $ticket->setPhoneNumberId($phoneNumberId);
        }
        $this->em->persist($ticket);
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    private function addEvent(int $ticketId, string $type, ?\DateTimeImmutable $createdAt = null): void
    {
        $event = new EventoDeTicket($ticketId, $type, 'system');
        $this->em->persist($event);
        $this->em->flush();

        if ($createdAt !== null) {
            $this->em->getConnection()->executeStatement(
                'UPDATE ticket_events SET created_at = ? WHERE id = ?',
                [$createdAt->format('Y-m-d H:i:s'), $event->getId()]
            );
        }
    }

    public function testMatchesAClosedTicketWhoseLatestEventIsWindowExpiredClosed(): void
    {
        $ticketId = $this->createClosedTicket();
        $this->addEvent($ticketId, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO);

        $found = $this->repo->findCerradoPorVentanaVencidaByContacto($this->contact);

        self::assertNotNull($found);
        self::assertSame($ticketId, $found->getId());
    }

    public function testDoesNotMatchWhenTicketHasZeroEvents(): void
    {
        $this->createClosedTicket();

        $found = $this->repo->findCerradoPorVentanaVencidaByContacto($this->contact);

        self::assertNull($found);
    }

    public function testDoesNotMatchWhenLatestEventIsADifferentType(): void
    {
        $ticketId = $this->createClosedTicket();
        $this->addEvent($ticketId, 'etiqueta_agregada', new \DateTimeImmutable('-2 hours'));
        $this->addEvent($ticketId, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO, new \DateTimeImmutable('-3 hours'));
        // Latest by created_at is 'etiqueta_agregada' (2h ago is more recent than 3h ago).

        $found = $this->repo->findCerradoPorVentanaVencidaByContacto($this->contact);

        self::assertNull($found);
    }

    public function testDoesNotMatchAClosedForAnotherReasonTicket(): void
    {
        $ticketId = $this->createClosedTicket();
        $this->addEvent($ticketId, 'auto_closed');

        $found = $this->repo->findCerradoPorVentanaVencidaByContacto($this->contact);

        self::assertNull($found);
    }

    public function testTieBreaksOnIdDescWhenCreatedAtIsIdentical(): void
    {
        $ticketId = $this->createClosedTicket();
        $sameInstant = new \DateTimeImmutable('2026-01-01 12:00:00');

        // Insert WINDOW_EXPIRED_CLOSED first (lower id), then a disqualifying
        // event with the SAME created_at but a higher id — id DESC must win.
        $this->addEvent($ticketId, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO, $sameInstant);
        $this->addEvent($ticketId, 'etiqueta_agregada', $sameInstant);

        $found = $this->repo->findCerradoPorVentanaVencidaByContacto($this->contact);

        self::assertNull($found, 'The higher-id (newer insert) etiqueta_agregada event must win the tie-break, disqualifying the ticket');
    }

    public function testReturnsTheMostRecentByUpdatedAtWhenMultipleTicketsAreEligible(): void
    {
        $olderTicketId = $this->createClosedTicket();
        $this->addEvent($olderTicketId, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO);
        $this->em->getConnection()->executeStatement(
            'UPDATE tickets SET updated_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), $olderTicketId]
        );

        $newerTicketId = $this->createClosedTicket();
        $this->addEvent($newerTicketId, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO);
        $this->em->getConnection()->executeStatement(
            'UPDATE tickets SET updated_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $newerTicketId]
        );

        $found = $this->repo->findCerradoPorVentanaVencidaByContacto($this->contact);

        self::assertNotNull($found);
        self::assertSame($newerTicketId, $found->getId());
    }

    public function testOptionalPhoneNumberIdFilterScopesTheMatch(): void
    {
        $ticketA = $this->createClosedTicket('phone-a');
        $this->addEvent($ticketA, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO);

        $ticketB = $this->createClosedTicket('phone-b');
        $this->addEvent($ticketB, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO);

        $found = $this->repo->findCerradoPorVentanaVencidaByContacto($this->contact, 'phone-b');

        self::assertNotNull($found);
        self::assertSame($ticketB, $found->getId());
    }
}
