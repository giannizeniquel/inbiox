<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Conversacion\Query\GetTicketMessagesQuery;
use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Conversacion\Mensaje;
use App\Conversacion\TipoDeRemitente;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * spec.md "Query and DTO Exposure": `GetTicketMessagesQuery` (both SELECT
 * variants) and `MessageDTO::toArray()` must expose `sender_agent_id` and
 * `sender_type`, including null for legacy (pre-migration) rows.
 */
class GetTicketMessagesQueryAttributionTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM messages');
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

    private function createTicketWithMessages(): int
    {
        $agent = new Agente('Query Test Agent');
        $this->em->persist($agent);
        $this->em->flush();
        $agentId = $agent->getId();
        \assert($agentId !== null);

        $contact = new Contacto();
        $contact->setTelefono('+5491100000500');
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado('active');
        $this->em->persist($ticket);
        $this->em->flush();

        // Attributed message (post-migration write path)
        $attributed = new Mensaje();
        $attributed->setConversacion($conversation);
        $attributed->setDireccion('outbound');
        $attributed->setContenido('Attributed reply');
        $attributed->setAgenteRemitenteId($agentId);
        $attributed->setTipoDeRemitente(TipoDeRemitente::AGENTE);
        $this->em->persist($attributed);

        // Legacy message (simulates a pre-migration row: never touch the setters)
        $legacy = new Mensaje();
        $legacy->setConversacion($conversation);
        $legacy->setDireccion('inbound');
        $legacy->setContenido('Legacy message');
        $this->em->persist($legacy);

        $this->em->flush();

        $ticketId = $ticket->getId();
        \assert($ticketId !== null);
        $this->em->clear();

        return $ticketId;
    }

    public function testForwardQueryExposesAttributionAndLegacyNull(): void
    {
        $ticketId = $this->createTicketWithMessages();

        $query = static::getContainer()->get(GetTicketMessagesQuery::class);
        $result = $query->execute($ticketId, direction: 'forward');

        self::assertCount(2, $result['items']);

        // Insert order (chronological, ASC): attributed message first, then legacy.
        $attributed = $result['items'][0]->toArray();
        self::assertSame('Attributed reply', $attributed['content']);
        self::assertIsInt($attributed['sender_agent_id']);
        self::assertSame('agent', $attributed['sender_type']);

        $legacy = $result['items'][1]->toArray();
        self::assertSame('Legacy message', $legacy['content']);
        self::assertNull($legacy['sender_agent_id']);
        self::assertNull($legacy['sender_type']);
    }

    public function testBackwardQueryExposesAttributionAndLegacyNull(): void
    {
        $ticketId = $this->createTicketWithMessages();

        $query = static::getContainer()->get(GetTicketMessagesQuery::class);
        $result = $query->execute($ticketId, direction: 'backward');

        self::assertCount(2, $result['items']);

        // Backward query result is reversed back to chronological order too.
        $attributed = $result['items'][0]->toArray();
        self::assertIsInt($attributed['sender_agent_id']);
        self::assertSame('agent', $attributed['sender_type']);

        $legacy = $result['items'][1]->toArray();
        self::assertNull($legacy['sender_agent_id']);
        self::assertNull($legacy['sender_type']);
    }
}
