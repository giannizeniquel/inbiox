<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agente\Agent;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Ticket\Ticket;
use App\Ticket\TicketStatus;
use App\Administracion\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Safety-critical integration matrix for ticket-closure-authorization
 * (design.md D3, tasks.md 2.3).
 *
 * The single most load-bearing assertion in this whole change:
 * `POST /tickets/{id}/close` is structurally always an *unowned* close
 * (resolving unassigns), so it returns 403 for EVERY ROLE_AGENT — including
 * the ticket's own resolver — and 200 only for supervisor/admin.
 */
class TicketClosureAuthorizationTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // A few tests in this class issue two authenticated requests in
        // sequence (e.g. resolve then close, to prove D3's own-ticket
        // sequencing). The default per-request kernel reboot has been
        // observed to intermittently drop the second request's JWT
        // authentication in this environment; disabling it keeps both
        // requests within the same booted container.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // This class authenticates a distinct user per test case (dozens of
        // role/ownership combinations) through the real login endpoint.
        // Reset Symfony's login-throttling rate limiter (security.yaml
        // `login_throttling`, 25 attempts/minute per IP) so this class's
        // own volume never trips it — a rate-limiter 401 here would be
        // indistinguishable from a real authorization regression.
        if (static::getContainer()->has('cache.rate_limiter')) {
            static::getContainer()->get('cache.rate_limiter')->clear();
        }

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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    private function createAuthenticatedUser(string $email, array $roles, ?Agent $agent = null): string
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $this->em->persist($user);

        if ($agent !== null) {
            $agent->setUser($user);
            $this->em->persist($agent);
        }

        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => $email,
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'login must succeed for ' . $email);

        $data = \json_decode($response->getContent(), true);

        return $data['access_token'];
    }

    private function createAgent(string $name): Agent
    {
        $agent = new Agent($name);
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function createTicket(string $status, ?int $assignedAgentId = null): int
    {
        $contact = new Contact();
        $contact->setPhone('+549110000' . \random_int(1000, 9999));
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus($status);
        if ($assignedAgentId !== null) {
            $ticket->setAssignedAgentId($assignedAgentId);
        }
        $this->em->persist($ticket);
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    private function request(string $method, string $uri, string $token, ?array $body = null): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            $body !== null ? \json_encode($body) : null,
        );

        return $this->client->getResponse();
    }

    private function assertNoMutation(int $ticketId, string $expectedStatus): void
    {
        $status = $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticketId]);
        self::assertSame($expectedStatus, $status, 'a denied request must not mutate the ticket status');

        $eventCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ticket_events WHERE ticket_id = ?', [$ticketId]);
        self::assertSame(0, $eventCount, 'a denied request must not write any ticket_events row');

        $assignmentCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]);
        self::assertSame(0, $assignmentCount, 'a denied request must not write any assignments row');
    }

    // ── Owning agent → 200 ──

    /**
     * @dataProvider ownedClosingActionProvider
     */
    public function testOwningAgentCanCloseOrResolveOwnTicket(string $method, string $uri, ?array $body, string $fromStatus): void
    {
        $agent = $this->createAgent('Owning Agent');
        $token = $this->createAuthenticatedUser('owning-agent@inbiox.local', ['ROLE_AGENT'], $agent);
        $ticketId = $this->createTicket($fromStatus, $agent->getId());

        $response = $this->request($method, \str_replace('{id}', (string) $ticketId, $uri), $token, $body);

        self::assertSame(200, $response->getStatusCode());
    }

    public static function ownedClosingActionProvider(): array
    {
        return [
            'resolve, active'                  => ['POST', '/api/tickets/{id}/resolve', null, TicketStatus::ACTIVE->value],
            'transition to resolved, active'   => ['POST', '/api/tickets/{id}/transition', ['status' => 'resolved'], TicketStatus::ACTIVE->value],
            'transition to closed, active'     => ['POST', '/api/tickets/{id}/transition', ['status' => 'closed'], TicketStatus::ACTIVE->value],
        ];
    }

    /**
     * design.md D3: `/close` requires a `resolved` ticket, and resolving
     * unassigns — so there is NO reachable "owning agent uses /close" case.
     * This absence is itself the safety-critical proof; see the next test.
     */
    public function testAgentCanNeverCloseAResolvedTicketEvenTheOneTheyJustResolvedThemselves(): void
    {
        $agent = $this->createAgent('Self Resolver');
        $token = $this->createAuthenticatedUser('self-resolver@inbiox.local', ['ROLE_AGENT'], $agent);
        $ticketId = $this->createTicket(TicketStatus::ACTIVE->value, $agent->getId());

        $resolveResponse = $this->request('POST', '/api/tickets/' . $ticketId . '/resolve', $token);
        self::assertSame(200, $resolveResponse->getStatusCode());

        // The ticket is now resolved AND unassigned (unassign-on-resolve).
        $status = $this->em->getConnection()->fetchOne('SELECT status, assigned_agent_id FROM tickets WHERE id = ?', [$ticketId]);

        $closeResponse = $this->request('POST', '/api/tickets/' . $ticketId . '/close', $token);
        self::assertSame(403, $closeResponse->getStatusCode());

        $data = \json_decode($closeResponse->getContent(), true);
        self::assertSame('unowned_ticket', $data['reason']);
    }

    // ── Non-owning agent → 403 not_ticket_owner ──

    /**
     * @dataProvider closingActionProvider
     */
    public function testNonOwningAgentIsDenied(string $method, string $uri, ?array $body, string $fromStatus): void
    {
        $ownerAgent = $this->createAgent('Owner Agent');
        $otherAgent = $this->createAgent('Other Agent');
        $token = $this->createAuthenticatedUser('other-agent@inbiox.local', ['ROLE_AGENT'], $otherAgent);
        $ticketId = $this->createTicket($fromStatus, $ownerAgent->getId());

        $response = $this->request($method, \str_replace('{id}', (string) $ticketId, $uri), $token, $body);

        self::assertSame(403, $response->getStatusCode());
        $data = \json_decode($response->getContent(), true);
        self::assertSame('not_ticket_owner', $data['reason']);
        self::assertSame('TICKET_CLOSURE_FORBIDDEN', $data['code']);
        $this->assertNoMutation($ticketId, $fromStatus);
    }

    // ── Agent with no linked Agent → 403 no_linked_agent ──

    /**
     * @dataProvider closingActionProvider
     */
    public function testAgentWithNoLinkedAgentIsDenied(string $method, string $uri, ?array $body, string $fromStatus): void
    {
        $ownerAgent = $this->createAgent('Owner Agent 2');
        $token = $this->createAuthenticatedUser('no-linked-agent@inbiox.local', ['ROLE_AGENT']);
        $ticketId = $this->createTicket($fromStatus, $ownerAgent->getId());

        $response = $this->request($method, \str_replace('{id}', (string) $ticketId, $uri), $token, $body);

        self::assertSame(403, $response->getStatusCode());
        $data = \json_decode($response->getContent(), true);
        self::assertSame('no_linked_agent', $data['reason']);
        $this->assertNoMutation($ticketId, $fromStatus);
    }

    // ── Any agent, unowned ticket → 403 unowned_ticket ──

    /**
     * @dataProvider closingActionProvider
     */
    public function testAnyAgentOnUnownedTicketIsDenied(string $method, string $uri, ?array $body, string $fromStatus): void
    {
        $agent = $this->createAgent('Any Agent');
        $token = $this->createAuthenticatedUser('any-agent@inbiox.local', ['ROLE_AGENT'], $agent);
        $ticketId = $this->createTicket($fromStatus, null);

        $response = $this->request($method, \str_replace('{id}', (string) $ticketId, $uri), $token, $body);

        self::assertSame(403, $response->getStatusCode());
        $data = \json_decode($response->getContent(), true);
        self::assertSame('unowned_ticket', $data['reason']);
        $this->assertNoMutation($ticketId, $fromStatus);
    }

    public static function closingActionProvider(): array
    {
        return [
            'resolve'                    => ['POST', '/api/tickets/{id}/resolve', null, TicketStatus::ACTIVE->value],
            'transition to resolved'     => ['POST', '/api/tickets/{id}/transition', ['status' => 'resolved'], TicketStatus::ACTIVE->value],
            'transition to closed'       => ['POST', '/api/tickets/{id}/transition', ['status' => 'closed'], TicketStatus::ACTIVE->value],
            'close (from resolved)'      => ['POST', '/api/tickets/{id}/close', null, TicketStatus::RESOLVED->value],
        ];
    }

    // ── Supervisor / admin → 200 on any ticket ──

    /**
     * @dataProvider privilegedRoleProvider
     */
    public function testPrivilegedRoleCanResolveOrCloseAnyTicket(string $role): void
    {
        $ownerAgent = $this->createAgent('Some Owner');
        $token = $this->createAuthenticatedUser('privileged-' . \strtolower($role) . '@inbiox.local', [$role]);

        $ticketId = $this->createTicket(TicketStatus::ACTIVE->value, $ownerAgent->getId());
        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/resolve', $token);
        self::assertSame(200, $response->getStatusCode());

        $ticketId2 = $this->createTicket(TicketStatus::RESOLVED->value, null);
        $response2 = $this->request('POST', '/api/tickets/' . $ticketId2 . '/close', $token);
        self::assertSame(200, $response2->getStatusCode());
    }

    public static function privilegedRoleProvider(): array
    {
        return [
            'supervisor' => ['ROLE_SUPERVISOR'],
            'admin'      => ['ROLE_ADMIN'],
        ];
    }

    // ── Over-blocking regression: non-closing transitions are never gated ──

    /**
     * @dataProvider nonClosingTargetProvider
     */
    public function testNonClosingTransitionsAreNotGatedByOwnership(string $targetStatus, string $fromStatus): void
    {
        $ownerAgent = $this->createAgent('Owner For Non Closing');
        $otherAgent = $this->createAgent('Other For Non Closing');
        $token = $this->createAuthenticatedUser('other-non-closing@inbiox.local', ['ROLE_AGENT'], $otherAgent);
        $ticketId = $this->createTicket($fromStatus, $ownerAgent->getId());

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/transition', $token, ['status' => $targetStatus]);

        self::assertSame(200, $response->getStatusCode());
    }

    public static function nonClosingTargetProvider(): array
    {
        return [
            'to active'  => [TicketStatus::ACTIVE->value, TicketStatus::NEW->value],
            'to waiting' => [TicketStatus::WAITING->value, TicketStatus::ACTIVE->value],
        ];
    }

    // ── Contract preservation ──

    public function testTransitionWithNoStatusStillReturns400Unchanged(): void
    {
        $token = $this->createAuthenticatedUser('no-status@inbiox.local', ['ROLE_AGENT']);
        $ticketId = $this->createTicket(TicketStatus::ACTIVE->value, null);

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/transition', $token, []);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testWrongCaseStatusStillReturns422FromTheFsm(): void
    {
        $agent = $this->createAgent('Wrong Case Owner');
        $token = $this->createAuthenticatedUser('wrong-case@inbiox.local', ['ROLE_AGENT'], $agent);
        $ticketId = $this->createTicket(TicketStatus::ACTIVE->value, $agent->getId());

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/transition', $token, ['status' => 'CLOSED']);

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * @dataProvider nonexistentTicketActionProvider
     */
    public function testNonexistentTicketStillReturns400NotFourOhFour(string $method, string $uri, ?array $body): void
    {
        $token = $this->createAuthenticatedUser('nonexistent-ticket@inbiox.local', ['ROLE_AGENT']);

        $response = $this->request($method, \str_replace('{id}', '999999', $uri), $token, $body);

        self::assertSame(400, $response->getStatusCode());
    }

    public static function nonexistentTicketActionProvider(): array
    {
        return [
            'resolve' => ['POST', '/api/tickets/{id}/resolve', null],
            'close'   => ['POST', '/api/tickets/{id}/close', null],
        ];
    }
}
