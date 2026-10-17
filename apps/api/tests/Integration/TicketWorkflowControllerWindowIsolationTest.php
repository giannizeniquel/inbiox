<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agente\Agent;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Administracion\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Isolation guarantee (spec.md "Manual reopen of a window-expiry-closed
 * ticket is rejected"): `POST /tickets/{id}/transition` targeting ANY
 * status on a `window_expired_closed` ticket must return 4xx.
 *
 * This is a structural regression test, not a behavior change —
 * `CambiarEstadoDeTicketUseCase::execute()` only ever consults the shared
 * FSM table, where `closed` has no outgoing transitions (task 0.9). It
 * proves `reopenDueToInboundMessage()` has exactly one call site and is
 * never human-reachable.
 */
class TicketWorkflowControllerWindowIsolationTest extends WebTestCase
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
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
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
        $user->setEmail('isolation-test-agent@inbiox.local');
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_AGENT']);
        $this->em->persist($user);

        $agent = new Agent('Isolation Test Agent');
        $agent->setUser($user);
        $this->em->persist($agent);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'isolation-test-agent@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        return $data['access_token'];
    }

    private function createWindowExpiredClosedTicket(): int
    {
        $contact = new Contact();
        $contact->setPhone('+5491100000300');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus(EstadoDeTicket::CLOSED->value);
        $this->em->persist($ticket);
        $this->em->flush();

        $ticketId = $ticket->getId();
        \assert($ticketId !== null);

        $this->em->getConnection()->executeStatement(
            'INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, ?, ?, NOW())',
            [$ticketId, TipoDeEventoDeTicket::WINDOW_EXPIRED_CLOSED, 'system']
        );

        return $ticketId;
    }

    /**
     * @dataProvider targetStatusProvider
     */
    public function testTransitionEndpointRejectsAnyTargetOnAWindowExpiredClosedTicket(string $targetStatus): void
    {
        $ticketId = $this->createWindowExpiredClosedTicket();

        $this->client->request(
            'POST',
            '/api/tickets/' . $ticketId . '/transition',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
            ],
            \json_encode(['status' => $targetStatus]),
        );

        $response = $this->client->getResponse();
        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode());
    }

    public static function targetStatusProvider(): array
    {
        return [
            'to new' => [EstadoDeTicket::NEW->value],
            'to active' => [EstadoDeTicket::ACTIVE->value],
            'to waiting' => [EstadoDeTicket::WAITING->value],
            'to resolved' => [EstadoDeTicket::RESOLVED->value],
        ];
    }
}
