<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Conversacion\UseCase\ProcesarMensajeSalienteUseCase;
use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Compartido\Outbox\EventoOutbox;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for design.md D5/D6: a human agent reply now emits a
 * `MessageCreatedEvent` to the outbox (previously only inbound messages did),
 * following the `Sprint09BOutboxTest` pattern.
 */
class MessageAgentAttributionOutboxTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM processed_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM rule_execution_logs');
        $conn->executeStatement('DELETE FROM ticket_tags');
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

    public function testAgentReplyProducesOneOutboxRowWithMessageIdAndContent(): void
    {
        $agent = new Agente('Outbox Agent');
        $this->em->persist($agent);
        $this->em->flush();
        $agentId = $agent->getId();
        \assert($agentId !== null);

        $contact = new Contacto();
        $contact->setTelefono('+5491100000300');
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($ticket);
        $this->em->flush();

        $ticketId = $ticket->getId();
        \assert($ticketId !== null);
        $this->em->clear();

        $useCase = static::getContainer()->get(ProcesarMensajeSalienteUseCase::class);
        $result = $useCase->procesarMensajeSaliente($ticketId, 'Thanks for reaching out', $agentId);

        $outboxRepo = $this->em->getRepository(EventoOutbox::class);
        $events = $outboxRepo->findAll();

        $messageCreatedEvents = \array_values(\array_filter(
            $events,
            fn (EventoOutbox $e) => $e->getTipoDeEvento() === TipoDeEventoDeOutbox::MENSAJE_CREADO->value
        ));

        self::assertCount(1, $messageCreatedEvents, 'Exactly one MessageCreatedEvent must be persisted to the outbox');

        $payload = $messageCreatedEvents[0]->getPayload();
        self::assertSame($result['message_id'], $payload['message_id']);
        self::assertSame('Thanks for reaching out', $payload['content']);
        self::assertSame('saliente', $payload['direction']);
        self::assertArrayHasKey('sender_name', $payload);
        self::assertSame('Outbox Agent', $payload['sender_name']);
    }
}
