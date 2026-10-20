<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Audit classification matrix (design.md D2/D4/D5, tasks.md 4.1).
 *
 * Verifies the controller classifies each successful manual resolve/close
 * into exactly one owner/override/unowned `ticket_events` row, attributed
 * to the real acting user — never the literal `'system'`.
 */
class TicketClosureAuditClassificationTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // See TicketClosureAuthorizationTest for why: this class also logs
        // in a distinct user per test case through the real login endpoint,
        // which can trip Symfony's login-throttling rate limiter when run
        // back-to-back with sibling test classes doing the same.
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

    private function createAuthenticatedUser(string $email, array $roles, ?Agente $agent = null): array
    {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $this->em->persist($user);

        if ($agent !== null) {
            $agent->setUsuario($user);
            $this->em->persist($agent);
        }

        $this->em->flush();
        $userId = $user->getId();
        \assert($userId !== null);

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => $email,
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);

        return ['token' => $data['access_token'], 'user_id' => $userId];
    }

    private function createAgent(string $name): Agente
    {
        $agent = new Agente($name);
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function createTicket(string $status, ?int $assignedAgentId = null): int
    {
        $contact = new Contacto();
        $contact->setTelefono('+549110001' . \random_int(1000, 9999));
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado($status);
        if ($assignedAgentId !== null) {
            $ticket->setAgenteAsignadoId($assignedAgentId);
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

    /** @return array{type: string, source: string} */
    private function theOnlyTicketEvent(int $ticketId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT type, source FROM ticket_events WHERE ticket_id = ?',
            [$ticketId]
        );

        self::assertCount(1, $rows, 'exactly one ticket_events row must be written per successful action');

        return $rows[0];
    }

    private function assignmentCount(int $ticketId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM assignments WHERE ticket_id = ?', [$ticketId]);
    }

    private function latestAssignmentAssignedBy(int $ticketId): ?string
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT assigned_by FROM assignments WHERE ticket_id = ? ORDER BY id DESC LIMIT 1',
            [$ticketId]
        ) ?: null;
    }

    public function testOwnerClosesOwnTicketViaTransitionProducesManualClosedOwner(): void
    {
        $agent = $this->createAgent('Owner Closer');
        ['token' => $token, 'user_id' => $userId] = $this->createAuthenticatedUser('owner-closer@inbiox.local', ['ROLE_AGENT'], $agent);
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVO->value, $agent->getId());

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/transicionar', $token, ['estado' => 'cerrado']);
        self::assertSame(200, $response->getStatusCode());

        $event = $this->theOnlyTicketEvent($ticketId);
        self::assertSame(TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE, $event['type']);
        self::assertSame('u:' . $userId, $event['source']);
        self::assertSame(1, $this->assignmentCount($ticketId));
        self::assertSame('u:' . $userId, $this->latestAssignmentAssignedBy($ticketId));
    }

    public function testSupervisorOverridesAnotherAgentsTicketViaTransitionProducesManualClosedOverride(): void
    {
        $ownerAgent = $this->createAgent('Override Owner');
        ['token' => $token, 'user_id' => $userId] = $this->createAuthenticatedUser('override-supervisor@inbiox.local', ['ROLE_SUPERVISOR']);
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVO->value, $ownerAgent->getId());

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/transicionar', $token, ['estado' => 'cerrado']);
        self::assertSame(200, $response->getStatusCode());

        $event = $this->theOnlyTicketEvent($ticketId);
        self::assertSame(TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO, $event['type']);
        self::assertSame('u:' . $userId, $event['source']);
    }

    public function testSupervisorClosesViaCloseEndpointProducesManualClosedUnowned(): void
    {
        // design.md D3: /close always sees an unowned (resolved) ticket.
        ['token' => $token, 'user_id' => $userId] = $this->createAuthenticatedUser('unowned-close-supervisor@inbiox.local', ['ROLE_SUPERVISOR']);
        $ticketId = $this->createTicket(EstadoDeTicket::RESUELTO->value, null);

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/cerrar', $token);
        self::assertSame(200, $response->getStatusCode());

        $event = $this->theOnlyTicketEvent($ticketId);
        self::assertSame(TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE, $event['type']);
        self::assertSame('u:' . $userId, $event['source']);
        self::assertSame(0, $this->assignmentCount($ticketId), 'no prior owner → zero new assignments rows');
    }

    public function testOwnerResolvesOwnTicketProducesManualResolvedOwner(): void
    {
        $agent = $this->createAgent('Owner Resolver');
        ['token' => $token, 'user_id' => $userId] = $this->createAuthenticatedUser('owner-resolver@inbiox.local', ['ROLE_AGENT'], $agent);
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVO->value, $agent->getId());

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/resolver', $token);
        self::assertSame(200, $response->getStatusCode());

        $event = $this->theOnlyTicketEvent($ticketId);
        self::assertSame(TipoDeEventoDeTicket::MANUAL_RESUELTO_RESPONSABLE, $event['type']);
        self::assertSame('u:' . $userId, $event['source']);
    }

    public function testSupervisorResolvesAnotherAgentsTicketProducesManualResolvedOverride(): void
    {
        $ownerAgent = $this->createAgent('Resolve Override Owner');
        ['token' => $token, 'user_id' => $userId] = $this->createAuthenticatedUser('resolve-override-supervisor@inbiox.local', ['ROLE_SUPERVISOR']);
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVO->value, $ownerAgent->getId());

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/resolver', $token);
        self::assertSame(200, $response->getStatusCode());

        $event = $this->theOnlyTicketEvent($ticketId);
        self::assertSame(TipoDeEventoDeTicket::MANUAL_RESUELTO_FORZADO, $event['type']);
        self::assertSame('u:' . $userId, $event['source']);
    }

    /**
     * Pins design.md D2's reachability claim: a supervisor resolving an
     * `ReglaDeReaperturaAutomatica`-reopened (therefore unowned) ticket is a genuine
     * `manual_resolved_unowned` case, not a hypothetical one.
     */
    public function testSupervisorResolvesAnAutoReopenedUnownedTicketProducesManualResolvedUnowned(): void
    {
        // A `resolved` ticket is always unowned by construction (D3: the
        // prior resolve already unassigned it) — so the fixture must start
        // unowned too, matching the only reachable real state.
        $ticketId = $this->createTicket(EstadoDeTicket::RESUELTO->value, null);

        // Simulate ReglaDeReaperturaAutomatica::execute()'s exact call site: resolved →
        // waiting, via the system actor default. The ticket stays unowned.
        $transitionUseCase = static::getContainer()->get(CambiarEstadoDeTicketUseCase::class);
        $transitionUseCase->execute($ticketId, EstadoDeTicket::EN_ESPERA->value, false, false);

        ['token' => $token, 'user_id' => $userId] = $this->createAuthenticatedUser('reopen-resolve-supervisor@inbiox.local', ['ROLE_SUPERVISOR']);

        $response = $this->request('POST', '/api/tickets/' . $ticketId . '/resolver', $token);
        self::assertSame(200, $response->getStatusCode());

        $event = $this->theOnlyTicketEvent($ticketId);
        self::assertSame(TipoDeEventoDeTicket::MANUAL_RESUELTO_SIN_RESPONSABLE, $event['type']);
        self::assertSame('u:' . $userId, $event['source']);
        self::assertSame(0, $this->assignmentCount($ticketId));
    }
}
