<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agente\Agent;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Ticket\Ticket;
use App\Administracion\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level test for `POST /api/provider/send` on an expired WhatsApp 24h
 * conversation window.
 *
 * spec.md "Free-Text Send Blocking Outside the Window" (manual reply
 * scenario). Mirrors the existing 422/`MESSAGE_TOO_OLD` error shape
 * (design.md: caller integration table).
 */
class ProviderProxyControllerConversationWindowTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;
    private string $authToken = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM rule_execution_logs');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');

        $this->authToken = $this->createAuthenticatedUser();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    private function createAuthenticatedUser(): string
    {
        $user = new User();
        $user->setEmail('window-test-agent@inbiox.local');
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_AGENT']);
        $this->em->persist($user);

        $agent = new Agent('Window Test Agent');
        $agent->setUser($user);
        $this->em->persist($agent);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'window-test-agent@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        return $data['access_token'];
    }

    private function createTicketWithLastInboundHoursAgo(float $hoursAgo): int
    {
        $contact = new Contact();
        $contact->setPhone('+5491100000099');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado('active');
        $this->em->persist($ticket);
        $this->em->flush();

        $ticketId = $ticket->getId();
        \assert($ticketId !== null);

        $this->em->getConnection()->executeStatement(
            'UPDATE tickets SET last_inbound_message_at = DATE_SUB(NOW(), INTERVAL :minutes MINUTE) WHERE id = :id',
            ['minutes' => (int) round($hoursAgo * 60), 'id' => $ticketId]
        );
        $this->em->clear();

        return $ticketId;
    }

    public function testSendReturns422WithConversationWindowExpiredTypeOnAnExpiredWindow(): void
    {
        $ticketId = $this->createTicketWithLastInboundHoursAgo(25);

        $this->client->request(
            'POST',
            '/api/provider/send',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
            ],
            \json_encode(['ticketId' => $ticketId, 'content' => 'Sorry for the delay']),
        );

        $response = $this->client->getResponse();
        self::assertSame(422, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('CONVERSATION_WINDOW_EXPIRED', $data['type']);
    }
}
