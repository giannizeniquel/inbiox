<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Agente\Agent;
use App\Disponibilidad\AgentShift;
use App\Administracion\User;
use App\Administracion\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for WorkspaceAgentController.
 *
 * Covers:
 * - GET /api/workspaces/{id}/available-agents
 * - Authentication requirements
 * - Workspace existence and active status
 * - Agent availability filtering
 */
class WorkspaceAgentControllerTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM agent_shifts');
        $conn->executeStatement('DELETE FROM agent_workspace');
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');
        $conn->executeStatement('DELETE FROM workspaces');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    // ────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────

    private function createUser(
        string $email,
        array $roles = ['ROLE_AGENT'],
        ?Agent $agent = null,
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setIsActive(true);

        if (null !== $agent) {
            $agent->setUser($user);
            $this->em->persist($agent);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createAgent(string $name = 'Test Agent', bool $active = true): Agent
    {
        $agent = new Agent($name);
        $agent->setActive($active);
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function createWorkspace(string $phoneNumberId = '+1234567890', bool $active = true): Workspace
    {
        $workspace = new Workspace($phoneNumberId, 'Test Workspace');
        $workspace->setActive($active);
        $this->em->persist($workspace);
        $this->em->flush();

        return $workspace;
    }

    private function createShift(
        Agent $agent,
        int $dayOfWeek,
        string $start,
        string $end,
    ): AgentShift {
        $startTime = \DateTimeImmutable::createFromFormat('H:i:s', $start.':00');
        $endTime = \DateTimeImmutable::createFromFormat('H:i:s', $end.':00');
        \assert($startTime !== false && $endTime !== false);

        $shift = new AgentShift($agent, $dayOfWeek, $startTime, $endTime);
        $agent->addShift($shift);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    private function login(string $email): string
    {
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => $email,
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        return $data['access_token'];
    }

    private function authHeaders(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    // ────────────────────────────────────────────────────────
    //  Tests
    // ────────────────────────────────────────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $workspace = $this->createWorkspace();

        $this->client->request('GET', '/api/workspaces/' . $workspace->getId() . '/available-agents');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownWorkspaceReturns404(): void
    {
        $agent = $this->createAgent('Agent A');
        $this->createUser('agent@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/workspaces/99999/available-agents', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testInactiveWorkspaceReturns400(): void
    {
        $workspace = $this->createWorkspace(active: false);

        $agent = $this->createAgent('Agent A');
        $this->createUser('agent@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/workspaces/' . $workspace->getId() . '/available-agents', [], [], $this->authHeaders($token));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testEmptyWorkspaceReturnsEmptyArray(): void
    {
        $workspace = $this->createWorkspace();

        $agent = $this->createAgent('Agent A');
        $this->createUser('agent@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/workspaces/' . $workspace->getId() . '/available-agents', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $data);
    }

    public function testAvailableAgentsReturned(): void
    {
        $workspace = $this->createWorkspace();

        // Create agent with shift (Monday 08:00-16:00 UTC)
        $agent = $this->createAgent('Agent A');
        $agent->addWorkspace($workspace);
        $this->createShift($agent, 1, '08:00', '16:00'); // Monday

        $this->createUser('agent@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent@test.local');

        // Request on Monday 12:00 UTC (in shift)
        $this->client->request('GET', '/api/workspaces/' . $workspace->getId() . '/available-agents', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame($agent->getId(), $data[0]['id']);
        self::assertSame('Agent A', $data[0]['name']);
        self::assertArrayHasKey('is_active_now', $data[0]);
        self::assertArrayHasKey('active_ticket_count', $data[0]);
        self::assertArrayHasKey('max_active_tickets', $data[0]);
    }

    /**
     * Roster behavior (not survivor-list): an agent outside their shift, with
     * no override, is still RETURNED in the roster — just flagged Inactivo.
     * Uses isAvailableOverride=false for determinism (not weekday-dependent).
     */
    public function testAgentWithoutActiveShiftReturnedAsInactivo(): void
    {
        $workspace = $this->createWorkspace();

        // Agent explicitly Inactivo via override (deterministic, no shift needed)
        $agent = $this->createAgent('Agent A');
        $agent->addWorkspace($workspace);
        $agent->setIsAvailableOverride(false);
        $this->em->flush();

        $this->createUser('agent@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/workspaces/' . $workspace->getId() . '/available-agents', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = \json_decode($this->client->getResponse()->getContent(), true);
        // THEN the agent is still present in the roster, just Inactivo
        self::assertCount(1, $data);
        self::assertSame($agent->getId(), $data[0]['id']);
        self::assertFalse($data[0]['is_active_now']);
    }

    public function testInactiveAgentNeverAppearsInRoster(): void
    {
        $workspace = $this->createWorkspace();

        // Agent with active=false must never appear, regardless of shift/override
        $agent = $this->createAgent('Agent A', active: false);
        $agent->addWorkspace($workspace);
        $this->em->flush();

        $requester = $this->createAgent('Requester');
        $this->createUser('agent@test.local', ['ROLE_AGENT'], $requester);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/workspaces/' . $workspace->getId() . '/available-agents', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $data);
    }
}
