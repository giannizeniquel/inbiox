<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level test for `POST /api/proveedor/enviar` on a resolved/closed ticket
 * replied to by a non-owning, non-privileged agent.
 *
 * spec.md "Resolved/Closed Ticket Ownership Guard" — non-owning agent
 * scenario. design.md D4: `ConflictoDeEstadoException` -> HTTP 409
 * `{"type": "TICKET_NOT_OWNED", ...}`. Mirrors the existing
 * `ProviderProxyControllerConversationWindowTest` pattern.
 */
class ProviderProxyControllerAutoClaimTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM processed_events');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM rule_execution_logs');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    private function authenticateAsAgent(): string
    {
        $user = new Usuario();
        $user->setEmail('non-owning-agent@inbiox.local');
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_AGENTE']);
        $this->em->persist($user);

        $agent = new Agente('Non-Owning Agent');
        $agent->setUsuario($user);
        $this->em->persist($agent);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'non-owning-agent@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);

        return $data['access_token'];
    }

    private function createResolvedTicketOwnedBySomeoneElse(): int
    {
        $owner = new Agente('Owner Agent');
        $this->em->persist($owner);
        $this->em->flush();

        $contact = new Contacto();
        $contact->setTelefono('+5491100000098');
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::RESUELTO->value);
        $ticket->setAgenteAsignadoId($owner->getId());
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($ticket);
        $this->em->flush();

        $ticketId = $ticket->getId();
        \assert($ticketId !== null);
        $this->em->clear();

        return $ticketId;
    }

    public function testSendReturns409WithTicketNotOwnedTypeWhenNonOwningAgentRepliesToAResolvedTicket(): void
    {
        $authToken = $this->authenticateAsAgent();
        $ticketId = $this->createResolvedTicketOwnedBySomeoneElse();

        $this->client->request(
            'POST',
            '/api/proveedor/enviar',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $authToken,
            ],
            \json_encode(['ticketId' => $ticketId, 'contenido' => 'I will take this one']),
        );

        $response = $this->client->getResponse();
        self::assertSame(409, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('TICKET_NOT_OWNED', $data['type']);
        self::assertArrayHasKey('mensaje', $data);

        // No message must have been persisted — the whole use-case
        // transaction rolled back on the ConflictoDeEstadoException.
        $conn = $this->em->getConnection();
        $messageCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM messages m INNER JOIN tickets t ON t.conversation_id = m.conversation_id WHERE t.id = ?',
            [$ticketId],
        );
        self::assertSame(0, $messageCount);
    }
}
