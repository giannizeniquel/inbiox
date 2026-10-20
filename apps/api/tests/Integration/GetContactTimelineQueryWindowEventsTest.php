<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Contacto\Query\GetLineaDeTiempoDeContactoQuery;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `GetLineaDeTiempoDeContactoQuery`'s new `ticket_events` UNION ALL branch
 * (design.md D6 allow-list): only `ventana_vencida_cerrado` and
 * `ventana_reabierta` appear; other `ticket_events` types (e.g. `etiqueta_agregada`)
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

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
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

        $types = array_map(fn ($e) => $e->tipo, $result['events']);

        self::assertContains('ticket.ventana_vencida_cerrado', $types);
        self::assertContains('ticket.ventana_reabierta', $types);
    }

    public function testOtherTicketEventTypesDoNotAppearInTheTimeline(): void
    {
        $this->addTicketEvent('etiqueta_agregada');
        $this->addTicketEvent('reapertura_automatica');

        $query = static::getContainer()->get(GetLineaDeTiempoDeContactoQuery::class);
        $result = $query->execute($this->contactId, null, 50);

        $types = array_map(fn ($e) => $e->tipo, $result['events']);

        self::assertNotContains('etiqueta_agregada', $types);
        self::assertNotContains('ticket.etiqueta_agregada', $types);
        self::assertNotContains('reapertura_automatica', $types);
        self::assertNotContains('ticket.reapertura_automatica', $types);
    }
}
