<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Conversacion\UseCase\HandleInboundMessageUseCase;
use App\Conversacion\UseCase\HandleOutboundMessageUseCase;
use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Conversacion\MessageSenderType;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for sender attribution at the write sites (spec.md
 * "Attribution at Human Agent Reply" / "Attribution at Inbound Contacto
 * Message", design.md D3).
 */
class MessageAttributionWriteSiteTest extends KernelTestCase
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

    private function createTicket(): int
    {
        $contact = new Contacto();
        $contact->setTelefono('+5491100000200');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
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

        return $ticketId;
    }

    public function testHandleOutboundMessageSetsAgentAttributionForAKnownAgent(): void
    {
        $agent = new Agente('Reply Agent');
        $this->em->persist($agent);
        $this->em->flush();
        $agentId = $agent->getId();
        \assert($agentId !== null);

        $ticketId = $this->createTicket();

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage($ticketId, 'Hi, how can I help?', $agentId);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT sender_agent_id, sender_type FROM messages WHERE id = ?',
            [$result['message_id']]
        );

        self::assertSame($agentId, (int) $row['sender_agent_id']);
        self::assertSame(MessageSenderType::AGENT->value, $row['sender_type']);
    }

    public function testHandleOutboundMessageSetsNullSenderAgentIdWhenAgentIdIsZero(): void
    {
        $ticketId = $this->createTicket();

        $useCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);
        $result = $useCase->processOutboundMessage($ticketId, 'System note', 0);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT sender_agent_id, sender_type FROM messages WHERE id = ?',
            [$result['message_id']]
        );

        self::assertNull($row['sender_agent_id']);
        self::assertSame(MessageSenderType::AGENT->value, $row['sender_type']);
    }

    public function testHandleInboundMessageSetsContactAttributionWithNullSenderAgentId(): void
    {
        $useCase = static::getContainer()->get(HandleInboundMessageUseCase::class);
        $result = $useCase->processInboundMessage('+5491155551234', 'Hello, I need help');

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT sender_agent_id, sender_type FROM messages WHERE id = ?',
            [$result['message_id']]
        );

        self::assertNull($row['sender_agent_id']);
        self::assertSame(MessageSenderType::CONTACT->value, $row['sender_type']);
    }
}
