<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Ticket\Ticket;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared fixtures for the phase 3a characterization tests.
 *
 * These tests pin the CURRENT HTTP behavior of controller actions that the
 * backend cleanup (phase 3) will refactor. They are a safety net, not a
 * specification: when a pinned behavior looks wrong, the test says so with a
 * "Characterization: current behavior, not necessarily desired." comment.
 *
 * Every test starts and ends with the tables it touches emptied, because the
 * test database is shared by the whole suite.
 */
abstract class CharacterizationTestCase extends WebTestCase
{
    protected const TEST_PASSWORD = 'test-password';

    /** Matches `bin2hex(random_bytes(8))`, the reference format of the 500 envelopes. */
    protected const REFERENCE_PATTERN = '/^[0-9a-f]{16}$/';

    protected ?KernelBrowser $client = null;
    protected ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep one container across requests, so that the login request and
        // the request under test share it, and so that service overrides made
        // with static::getContainer()->set() survive the login request.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Each test logs in at least once through the throttled login endpoint.
        if (static::getContainer()->has('cache.rate_limiter')) {
            static::getContainer()->get('cache.rate_limiter')->clear();
        }

        $this->wipeTables();
    }

    protected function tearDown(): void
    {
        if (null !== $this->em) {
            $this->wipeTables();
        }

        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    protected function connection(): Connection
    {
        return $this->em->getConnection();
    }

    private function wipeTables(): void
    {
        // A failed request may have closed the EntityManager; use a raw
        // connection that does not depend on its state.
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        foreach ([
            'DELETE FROM refresh_tokens',
            'DELETE FROM outbox_events',
            'DELETE FROM ticket_events',
            'DELETE FROM assignments',
            'DELETE FROM ticket_tags',
            'DELETE FROM messages',
            'DELETE FROM rule_execution_logs',
            'DELETE FROM agent_team',
            'DELETE FROM tickets',
            'DELETE FROM teams',
            'DELETE FROM agent_shifts',
            'DELETE FROM agent_workspace',
            'DELETE FROM conversations',
            'DELETE FROM contacts',
            'DELETE FROM agents',
            'DELETE FROM `user`',
            'DELETE FROM workspaces',
        ] as $statement) {
            $conn->executeStatement($statement);
        }
    }

    // ────────────────────────────────────────────────────────
    //  Fixtures
    // ────────────────────────────────────────────────────────

    protected function createUser(string $email, array $roles, ?Agente $agent = null): Usuario
    {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setActivo(true);
        $this->em->persist($user);

        if (null !== $agent) {
            $agent->setUsuario($user);
            $user->setAgente($agent);
            $this->em->persist($agent);
        }

        $this->em->flush();

        return $user;
    }

    protected function createAgent(string $name): Agente
    {
        $agent = new Agente($name);
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    protected function createWorkspace(string $phoneNumberId, string $displayName = 'Workspace'): EspacioDeTrabajo
    {
        $workspace = new EspacioDeTrabajo($phoneNumberId, $displayName);
        $this->em->persist($workspace);
        $this->em->flush();

        return $workspace;
    }

    protected function createContact(string $phone, ?string $name = null): Contacto
    {
        $contact = new Contacto();
        $contact->setTelefono($phone);
        $contact->setNombre($name);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    protected function createTicket(string $status, ?int $assignedAgentId = null, ?Contacto $contact = null): Ticket
    {
        $contact ??= $this->createContact('+54911' . \random_int(10000000, 99999999));

        $conversation = new Conversacion();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado($status);
        $ticket->setAgenteAsignadoId($assignedAgentId);
        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }

    /**
     * Creates a user (optionally linked to a new agent) and returns its access token.
     */
    protected function loginAs(string $email, array $roles, ?Agente $agent = null): string
    {
        $this->createUser($email, $roles, $agent);

        return $this->login($email);
    }

    protected function login(string $email): string
    {
        // Detach the fixtures, so the requests load fresh entities from the
        // database as they would in production. Create fixtures before login.
        $this->em->clear();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['email' => $email, 'password' => self::TEST_PASSWORD]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'login must succeed for ' . $email);

        return \json_decode($response->getContent(), true)['access_token'];
    }

    // ────────────────────────────────────────────────────────
    //  HTTP
    // ────────────────────────────────────────────────────────

    /**
     * @param array<mixed>|string|null $body an array is JSON-encoded; a string is sent raw
     */
    protected function request(string $method, string $uri, ?string $token, array|string|null $body = null, array $server = []): Response
    {
        // Each production request starts with an empty identity map.
        $this->em->clear();

        $server += ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $this->client->request(
            $method,
            $uri,
            [],
            [],
            $server,
            \is_array($body) ? \json_encode($body, \JSON_THROW_ON_ERROR) : $body,
        );

        return $this->client->getResponse();
    }

    /**
     * @return array<mixed>|null
     */
    protected function json(Response $response): ?array
    {
        $content = $response->getContent();
        if (false === $content || '' === $content) {
            return null;
        }

        return \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }

    protected function assertJsonResponse(int $status, ?array $expectedBody, Response $response): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($expectedBody, $this->json($response));
    }

    /**
     * Shape S1 in odd/fase-3/errores.md.
     */
    protected function assertInternalServerErrorEnvelope(Response $response): void
    {
        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(['error', 'reference'], \array_keys($body));
        self::assertSame('Internal server error', $body['error']);
        self::assertMatchesRegularExpression(self::REFERENCE_PATTERN, $body['reference']);
    }

    protected function assertSecurityForbidden(Response $response): void
    {
        // Framework-rendered AccessDeniedHttpException; only the status is pinned
        // because the body depends on the debug flag.
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    protected function countRows(string $table, string $where = '1 = 1', array $params = []): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
    }
}
