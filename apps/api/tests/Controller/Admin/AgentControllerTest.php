<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Agente\Agente;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for AgentController (admin agent management).
 *
 * Covers:
 * - CRUD operations for agents
 * - Role-based access control (ROLE_SUPERVISOR required)
 * - Soft delete (active = false)
 * - Serialization includes user_id and user_email
 */
class AgentControllerTest extends WebTestCase
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
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');
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
        bool $isActive = true,
        ?Agente $agent = null,
    ): Usuario {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setIsActive($isActive);

        if (null !== $agent) {
            $agent->setUsuario($user);
            $this->em->persist($agent);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createAgent(string $name = 'Test Agent'): Agente
    {
        $agent = new Agente($name);
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
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
    //  Access Control
    // ────────────────────────────────────────────────────────

    public function testListAgentsRequiresSupervisor(): void
    {
        $agent = $this->createAgent('Agent');
        $this->createUser('agent@test.local', ['ROLE_AGENT'], true, $agent);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/admin/agents', [], [], $this->authHeaders($token));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testListAgentsAllowedForSupervisor(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('GET', '/api/admin/agents', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testListAgentsAllowedForAdmin(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $this->client->request('GET', '/api/admin/agents', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testListAgentsRequiresAuth(): void
    {
        $this->client->request('GET', '/api/admin/agents');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  List Agents
    // ────────────────────────────────────────────────────────

    public function testListAgentsReturnsPaginatedResults(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        for ($i = 1; $i <= 5; $i++) {
            $this->createAgent("Agent {$i}");
        }

        $this->client->request('GET', '/api/admin/agents?page=1&limit=3', [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('data', $data);
        self::assertArrayHasKey('meta', $data);
        self::assertCount(3, $data['data']);
        self::assertTrue($data['meta']['has_next']);
        self::assertSame(5, $data['meta']['total']);
    }

    public function testListAgentsIncludesUserEmail(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Linked Agent');
        $this->createUser('linked@test.local', ['ROLE_AGENT'], true, $agent);

        $this->client->request('GET', '/api/admin/agents', [], [], $this->authHeaders($token));
        $data = \json_decode($this->client->getResponse()->getContent(), true);

        $agentData = $data['data'][0];
        self::assertArrayHasKey('user_id', $agentData);
        self::assertArrayHasKey('user_email', $agentData);
        self::assertSame('linked@test.local', $agentData['user_email']);
    }

    // ────────────────────────────────────────────────────────
    //  Get Single Agent
    // ────────────────────────────────────────────────────────

    public function testGetAgent(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Target Agent');

        $this->client->request('GET', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Target Agent', $data['name']);
    }

    public function testGetAgentReturns404ForNonExistent(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('GET', '/api/admin/agents/99999', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testGetAgentAllowedForAdmin(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Target Agent');

        $this->client->request('GET', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Create Agent
    // ────────────────────────────────────────────────────────

    public function testCreateAgent(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('POST', '/api/admin/agents', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'New Agent',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('New Agent', $data['name']);
        self::assertTrue($data['active']);
    }

    public function testCreateAgentRequiresName(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('POST', '/api/admin/agents', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateAgentWithoutTimezoneDefaultsToUtc(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('POST', '/api/admin/agents', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'New Agent',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('UTC', $data['timezone']);
    }

    public function testCreateAgentWithExplicitValidTimezone(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('POST', '/api/admin/agents', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'New Agent',
            'timezone' => 'America/Sao_Paulo',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('America/Sao_Paulo', $data['timezone']);
    }

    public function testCreateAgentWithInvalidTimezoneReturns400(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('POST', '/api/admin/agents', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'New Agent',
            'timezone' => 'Not/A_Zone',
        ]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateAgentWithNonStringTimezoneReturns400(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('POST', '/api/admin/agents', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'New Agent',
            'timezone' => 123,
        ]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateAgentAllowedForAdmin(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $this->client->request('POST', '/api/admin/agents', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'Admin Created Agent',
        ]));

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Update Agent
    // ────────────────────────────────────────────────────────

    public function testUpdateAgentName(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Old Name');

        $this->client->request('PUT', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'Updated Name',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Updated Name', $data['name']);
    }

    public function testUpdateAgentActiveStatus(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Toggle Agent');

        $this->client->request('PUT', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'active' => false,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertFalse($data['active']);
    }

    public function testUpdateAgentTimezone(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Timezone Agent');

        $this->client->request('PUT', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'timezone' => 'Europe/Lisbon',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Europe/Lisbon', $data['timezone']);

        $this->em->clear();
        $updatedAgent = $this->em->getRepository(Agente::class)->find($agent->getId());
        self::assertSame('Europe/Lisbon', $updatedAgent->getZonaHoraria());
    }

    public function testUpdateAgentOmittingTimezoneLeavesItUnchanged(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Timezone Agent');
        $agent->setZonaHoraria('Europe/Lisbon');
        $this->em->flush();

        $this->client->request('PUT', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'Timezone Agent Renamed',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Europe/Lisbon', $data['timezone']);
    }

    public function testUpdateAgentWithInvalidTimezoneReturns400(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Timezone Agent');

        $this->client->request('PUT', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'timezone' => 'Not/A_Zone',
        ]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $unchangedAgent = $this->em->getRepository(Agente::class)->find($agent->getId());
        self::assertSame('UTC', $unchangedAgent->getZonaHoraria());
    }

    public function testUpdateAgentWithNonStringTimezoneReturns400(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Timezone Agent');

        $this->client->request('PUT', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'timezone' => 123,
        ]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateAgentAllowedForAdmin(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Old Name');

        $this->client->request('PUT', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'Updated By Admin',
        ]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Delete Agent (Soft Delete)
    // ────────────────────────────────────────────────────────

    public function testDeleteAgentIsSoftDelete(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $agent = $this->createAgent('Delete Agent');

        $this->client->request('DELETE', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());

        // Verify the agent still exists but is deactivated
        $this->em->clear();
        $deletedAgent = $this->em->getRepository(Agente::class)->find($agent->getId());
        self::assertNotNull($deletedAgent, 'Agent must still exist (soft delete)');
        self::assertFalse($deletedAgent->isActivo(), 'Agent must be deactivated');
    }

    public function testDeleteNonExistentAgentReturns404(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('DELETE', '/api/admin/agents/99999', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteAgentAllowedForAdmin(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Delete Agent');

        $this->client->request('DELETE', "/api/admin/agents/{$agent->getId()}", [], [], $this->authHeaders($token));
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
    }
}
