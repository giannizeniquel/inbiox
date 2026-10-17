<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ticket\Query\GetDetalleDeTicketQuery;
use App\Ticket\Query\ListTicketsQuery;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `timestamps.last_inbound_message_at` REST baseline (design.md Phase 5,
 * tasks 5.1/5.2): `GetDetalleDeTicketQuery` and `ListTicketsQuery` must expose
 * the new field, sourced from `tickets.last_inbound_message_at`.
 */
class TicketQueriesLastInboundMessageAtTest extends KernelTestCase
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

    private function createTicket(?\DateTimeImmutable $lastInboundMessageAt): int
    {
        $contact = new Contact();
        $contact->setPhone('+5491100000700');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado('active');
        $ticket->setUltimoMensajeEntranteAt($lastInboundMessageAt);
        $this->em->persist($ticket);
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    public function testGetTicketDetailQueryExposesLastInboundMessageAt(): void
    {
        $lastInbound = new \DateTimeImmutable('2026-01-01 10:00:00');
        $ticketId = $this->createTicket($lastInbound);

        $query = static::getContainer()->get(GetDetalleDeTicketQuery::class);
        $dto = $query->execute($ticketId);

        self::assertNotNull($dto);
        self::assertArrayHasKey('last_inbound_message_at', $dto->timestamps);
        self::assertSame('2026-01-01T10:00:00Z', $dto->timestamps['last_inbound_message_at']);
    }

    public function testGetTicketDetailQueryReturnsNullWhenLastInboundMessageAtIsUnset(): void
    {
        $ticketId = $this->createTicket(null);

        $query = static::getContainer()->get(GetDetalleDeTicketQuery::class);
        $dto = $query->execute($ticketId);

        self::assertNotNull($dto);
        self::assertArrayHasKey('last_inbound_message_at', $dto->timestamps);
        self::assertNull($dto->timestamps['last_inbound_message_at']);
    }

    public function testListTicketsQueryExposesLastInboundMessageAt(): void
    {
        $this->createTicket(new \DateTimeImmutable('2026-01-01 10:00:00'));

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20);

        self::assertNotEmpty($result['items']);
        $item = $result['items'][0];
        self::assertArrayHasKey('last_inbound_message_at', $item->timestamps);
        self::assertNotNull($item->timestamps['last_inbound_message_at']);
    }
}
