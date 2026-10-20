<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Agente\Agente;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for UserController (admin user management).
 *
 * Covers:
 * - CRUD operations for users
 * - Role-based access control (ROLE_ADMINISTRADOR required)
 * - Soft delete (is_active = false)
 * - Email uniqueness
 * - Optional agent link on creation
 */
class UserControllerTest extends WebTestCase
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
    ): Usuario {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setActivo($isActive);

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

    public function testListUsersRequiresAdmin(): void
    {
        $agent = $this->createAgent('Agent');
        $this->createUser('agent@test.local', ['ROLE_AGENTE']);
        $user = $this->em->getRepository(Usuario::class)->findOneBy(['email' => 'agent@test.local']);
        $agent->setUsuario($user);
        $this->em->flush();

        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/administracion/usuarios', [], [], $this->authHeaders($token));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testListUsersRequiresAuth(): void
    {
        $this->client->request('GET', '/api/administracion/usuarios');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  List Users
    // ────────────────────────────────────────────────────────

    public function testListUsersReturnsPaginatedResults(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        // Create some users
        for ($i = 1; $i <= 5; $i++) {
            $this->createUser("user{$i}@test.local");
        }

        $this->client->request('GET', '/api/administracion/usuarios?page=1&limit=3', [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('data', $data);
        self::assertArrayHasKey('meta', $data);
        self::assertCount(3, $data['data']);
        self::assertTrue($data['meta']['haySiguiente']);
        self::assertSame(6, $data['meta']['total']); // 5 users + admin
    }

    public function testListUsersReturnsSerializedData(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->createUser('jane@test.local', ['ROLE_AGENTE']);

        $this->client->request('GET', '/api/administracion/usuarios', [], [], $this->authHeaders($token));
        $data = \json_decode($this->client->getResponse()->getContent(), true);

        $user = $data['data'][0]; // Most recent first
        self::assertArrayHasKey('id', $user);
        self::assertArrayHasKey('email', $user);
        self::assertArrayHasKey('roles', $user);
        self::assertArrayHasKey('activo', $user);
        self::assertArrayHasKey('agenteId', $user);
        self::assertArrayHasKey('agenteNombre', $user);
        self::assertArrayHasKey('creadoAt', $user);
        self::assertArrayHasKey('actualizadoAt', $user);
        self::assertIsInt($user['id']);
    }

    // ────────────────────────────────────────────────────────
    //  Get Single User
    // ────────────────────────────────────────────────────────

    public function testGetUserReturnsUser(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $user = $this->createUser('target@test.local', ['ROLE_AGENTE']);

        $this->client->request('GET', "/api/administracion/usuarios/{$user->getId()}", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('target@test.local', $data['email']);
    }

    public function testGetUserReturns404ForNonExistent(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('GET', '/api/administracion/usuarios/99999', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Create User
    // ────────────────────────────────────────────────────────

    public function testCreateUser(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('POST', '/api/administracion/usuarios', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'newuser@test.local',
            'password' => 'secure-pass',
            'roles' => ['ROLE_AGENTE'],
        ]));

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('newuser@test.local', $data['email']);
        self::assertContains('ROLE_AGENTE', $data['roles']);
        self::assertTrue($data['activo']);
    }

    public function testCreateUserWithOptionalAgentLink(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $agent = $this->createAgent('Support Agent');

        $this->client->request('POST', '/api/administracion/usuarios', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'linked@test.local',
            'password' => 'secure-pass',
            'roles' => ['ROLE_AGENTE'],
            'agenteId' => $agent->getId(),
        ]));

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('linked@test.local', $data['email']);
        self::assertSame($agent->getId(), $data['agenteId']);
        self::assertSame('Support Agent', $data['agenteNombre']);
    }

    public function testCreateUserReturns409OnDuplicateEmail(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->createUser('existing@test.local');

        $this->client->request('POST', '/api/administracion/usuarios', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'existing@test.local',
            'password' => 'secure-pass',
        ]));

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateUserRequiresEmailAndPassword(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('POST', '/api/administracion/usuarios', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'roles' => ['ROLE_AGENTE'],
        ]));

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Update User
    // ────────────────────────────────────────────────────────

    public function testUpdateUserEmail(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $user = $this->createUser('old@test.local');

        $this->client->request('PUT', "/api/administracion/usuarios/{$user->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'new@test.local',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('new@test.local', $data['email']);
    }

    public function testUpdateUserRoles(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $user = $this->createUser('roles@test.local', ['ROLE_AGENTE']);

        $this->client->request('PUT', "/api/administracion/usuarios/{$user->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'roles' => ['ROLE_ADMINISTRADOR', 'ROLE_SUPERVISOR'],
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertContains('ROLE_ADMINISTRADOR', $data['roles']);
    }

    public function testUpdateUserActiveStatus(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $user = $this->createUser('active@test.local');

        $this->client->request('PUT', "/api/administracion/usuarios/{$user->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'activo' => false,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertFalse($data['activo']);
    }

    // ────────────────────────────────────────────────────────
    //  Delete User (Soft Delete)
    // ────────────────────────────────────────────────────────

    public function testDeleteUserIsSoftDelete(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $user = $this->createUser('delete@test.local');

        $this->client->request('DELETE', "/api/administracion/usuarios/{$user->getId()}", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());

        // Verify the user still exists but is deactivated
        $this->em->clear();
        $deletedUser = $this->em->getRepository(Usuario::class)->find($user->getId());
        self::assertNotNull($deletedUser, 'User must still exist (soft delete)');
        self::assertFalse($deletedUser->isActivo(), 'User must be deactivated');
    }

    public function testDeleteNonExistentUserReturns404(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('DELETE', '/api/administracion/usuarios/99999', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
