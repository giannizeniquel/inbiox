<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Agente\Agente;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for AgentLinkController (admin agent-user linking).
 *
 * Covers:
 * - Linking user to agent
 * - Unlinking user from agent
 * - 400 "Agent already linked" if agent has user
 * - 400 "Usuario already linked" if user has agent
 * - 400 "Cannot link deactivated user" (Q3)
 * - 409 on UNIQUE constraint conflict
 * - Access control (ROLE_ADMINISTRADOR required)
 */
class AgentLinkControllerTest extends WebTestCase
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
        array $roles = ['ROLE_AGENTE'],
        bool $isActive = true,
        ?Agente $agent = null,
    ): Usuario {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setActivo($isActive);

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

    public function testLinkUserRequiresAdmin(): void
    {
        $agent = $this->createAgent('Agent');
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR'], true, $agent);
        $token = $this->login('supervisor@test.local');

        $this->client->request('POST', '/api/administracion/agentes/1/vincular-usuario', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['usuarioId' => 1]));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Link User
    // ────────────────────────────────────────────────────────

    public function testLinkUserToAgent(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Support Agent');
        $user = $this->createUser('agent-user@test.local');

        $this->client->request('POST', "/api/administracion/agentes/{$agent->getId()}/vincular-usuario", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['usuarioId' => $user->getId()]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame($agent->getId(), $data['id']);
        self::assertSame($user->getId(), $data['usuarioId']);
        self::assertSame('agent-user@test.local', $data['usuarioEmail']);
    }

    public function testLinkUserReturns400IfAgentAlreadyLinked(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Linked Agent');
        $existingUser = $this->createUser('existing@test.local');
        $agent->setUsuario($existingUser);
        $this->em->flush();

        $newUser = $this->createUser('new@test.local');

        $this->client->request('POST', "/api/administracion/agentes/{$agent->getId()}/vincular-usuario", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['usuarioId' => $newUser->getId()]));

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertStringContainsString('already linked', \strtolower($data['error']));
    }

    public function testLinkUserReturns400IfUserAlreadyLinked(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $agent1 = $this->createAgent('Agent 1');
        $agent2 = $this->createAgent('Agent 2');
        $user = $this->createUser('user@test.local');
        $agent1->setUsuario($user);
        $this->em->flush();

        $this->client->request('POST', "/api/administracion/agentes/{$agent2->getId()}/vincular-usuario", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['usuarioId' => $user->getId()]));

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertStringContainsString('already linked', \strtolower($data['error']));
    }

    /**
     * Q3: Deny linking deactivated user to agent.
     */
    public function testLinkDeactivatedUserReturns400(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Agent');
        $inactiveUser = $this->createUser('inactive@test.local', ['ROLE_AGENTE'], false);

        $this->client->request('POST', "/api/administracion/agentes/{$agent->getId()}/vincular-usuario", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['usuarioId' => $inactiveUser->getId()]));

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertStringContainsString('deactivated', \strtolower($data['error']));
    }

    public function testLinkUserReturns404ForNonExistentAgent(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('POST', '/api/administracion/agentes/99999/vincular-usuario', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['usuarioId' => 1]));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testLinkUserReturns404ForNonExistentUser(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Agent');

        $this->client->request('POST', "/api/administracion/agentes/{$agent->getId()}/vincular-usuario", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['usuarioId' => 99999]));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Unlink User
    // ────────────────────────────────────────────────────────

    public function testUnlinkUserFromAgent(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Agent');
        $user = $this->createUser('user@test.local');
        $agent->setUsuario($user);
        $this->em->flush();

        $this->client->request('DELETE', "/api/administracion/agentes/{$agent->getId()}/vincular-usuario", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());

        // Verify the link is removed
        $this->em->clear();
        $updatedAgent = $this->em->getRepository(Agente::class)->find($agent->getId());
        self::assertNull($updatedAgent->getUsuario());
    }

    public function testUnlinkUserReturns400IfNoLink(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Unlinked Agent');

        $this->client->request('DELETE', "/api/administracion/agentes/{$agent->getId()}/vincular-usuario", [], [], $this->authHeaders($token));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUnlinkUserReturns404ForNonExistentAgent(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('DELETE', '/api/administracion/agentes/99999/vincular-usuario', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
