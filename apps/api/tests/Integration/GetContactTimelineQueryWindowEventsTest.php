<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Contacto\Query\GetLineaDeTiempoDeContactoQuery;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `GetLineaDeTiempoDeContactoQuery`'s new `ticket_events` UNION ALL branch
 * (design.md D6 allow-list): only `window_expired_closed` and
 * `window_reopened` appear; other `ticket_events` types (e.g. `tag_added`)
 * must NOT appear — reading every type would be an out-of-scope behavior
 * change.
 */
class GetContactTimelineQueryWindowEventsTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?int $contactId = null;
    private ?int $ticketId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');

        $contact = new Contacto();
        $contact->setTelefono('+5491100000600');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::CERRADO->value);
        $this->em->persist($ticket);
        $this->em->flush();

        $this->contactId = $contact->getId();
        $this->ticketId = $ticket->getId();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    private function addTicketEvent(string $type): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, ?, ?, NOW())',
            [$this->ticketId, $type, 'system']
        );
    }

    public function testWindowExpiredClosedAndWindowReopenedEventsAppearInTheTimeline(): void
    {
        $this->addTicketEvent(TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO);
        $this->addTicketEvent(TipoDeEventoDeTicket::VENTANA_REABIERTO);

        $query = static::getContainer()->get(GetLineaDeTiempoDeContactoQuery::class);
        $result = $query->execute($this->contactId, null, 50);

        $types = array_map(fn ($e) => $e->type, $result['events']);

        self::assertContains('ticket.window_expired_closed', $types);
        self::assertContains('ticket.window_reopened', $types);
    }

    public function testOtherTicketEventTypesDoNotAppearInTheTimeline(): void
    {
        $this->addTicketEvent('tag_added');
        $this->addTicketEvent('auto_reopened');

        $query = static::getContainer()->get(GetLineaDeTiempoDeContactoQuery::class);
        $result = $query->execute($this->contactId, null, 50);

        $types = array_map(fn ($e) => $e->type, $result['events']);

        self::assertNotContains('tag_added', $types);
        self::assertNotContains('ticket.tag_added', $types);
        self::assertNotContains('auto_reopened', $types);
        self::assertNotContains('ticket.auto_reopened', $types);
    }
}
