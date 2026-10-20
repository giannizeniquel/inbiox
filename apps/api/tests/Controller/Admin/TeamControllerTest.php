<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Agente\Agente;
use App\Agente\Equipo;
use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for Admin/TeamController (Tier 2 #7 — Team/Area Entity, Slice 1).
 *
 * Covers administration spec scenarios:
 * - List/create/get/update/deactivate teams (admin+supervisor gated).
 * - Duplicate team name within same workspace rejected (409); same name across
 *   different workspaces allowed.
 * - Bulk agent assignment (`PUT /admin/teams/{id}/agents`) — all-or-nothing,
 *   rejects an agent outside the team's workspace.
 */
class TeamControllerTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM agent_team');
        $conn->executeStatement('UPDATE tickets SET team_id = NULL');
        $conn->executeStatement('DELETE FROM teams');
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
        array $roles = ['ROLE_AGENTE'],
        ?Agente $agent = null,
    ): Usuario {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setActivo(true);

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

    private function createWorkspace(string $phoneNumberId = '+1000000000', string $displayName = 'Workspace A'): EspacioDeTrabajo
    {
        $workspace = new EspacioDeTrabajo($phoneNumberId, $displayName);
        $this->em->persist($workspace);
        $this->em->flush();

        return $workspace;
    }

    private function createTeam(EspacioDeTrabajo $workspace, string $name = 'Support'): Equipo
    {
        $team = new Equipo($workspace, $name);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
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

    public function testListTeamsRequiresAuth(): void
    {
        $this->client->request('GET', '/api/administracion/equipos');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testListTeamsDeniedForPlainAgent(): void
    {
        $agent = $this->createAgent('Agent');
        $this->createUser('agent@test.local', ['ROLE_AGENTE'], $agent);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/administracion/equipos', [], [], $this->authHeaders($token));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testListTeamsAllowedForSupervisor(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('GET', '/api/administracion/equipos', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testListTeamsAllowedForAdmin(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('GET', '/api/administracion/equipos', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  List
    // ────────────────────────────────────────────────────────

    public function testListTeamsReturnsPaginatedResults(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $workspace = $this->createWorkspace();
        for ($i = 1; $i <= 3; $i++) {
            $this->createTeam($workspace, "Team {$i}");
        }

        $this->client->request('GET', '/api/administracion/equipos?page=1&limit=2', [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('data', $data);
        self::assertArrayHasKey('meta', $data);
        self::assertCount(2, $data['data']);
        self::assertTrue($data['meta']['haySiguiente']);
        self::assertSame(3, $data['meta']['total']);
    }

    // ────────────────────────────────────────────────────────
    //  Create
    // ────────────────────────────────────────────────────────

    public function testCreateTeam(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();

        $this->client->request('POST', '/api/administracion/equipos', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'espacioDeTrabajoId' => $workspace->getId(),
            'nombre' => 'Support',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Support', $data['nombre']);
        self::assertTrue($data['activo']);
        self::assertSame($workspace->getId(), $data['espacioDeTrabajoId']);
    }

    public function testCreateTeamDuplicateNameSameWorkspaceReturns409(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $this->createTeam($workspace, 'Support');

        $this->client->request('POST', '/api/administracion/equipos', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'espacioDeTrabajoId' => $workspace->getId(),
            'nombre' => 'Support',
        ]));

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTeamSameNameDifferentWorkspaceSucceeds(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspaceA = $this->createWorkspace('+1000000000', 'Workspace A');
        $workspaceB = $this->createWorkspace('+2000000000', 'Workspace B');
        $this->createTeam($workspaceA, 'Support');

        $this->client->request('POST', '/api/administracion/equipos', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'espacioDeTrabajoId' => $workspaceB->getId(),
            'nombre' => 'Support',
        ]));

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTeamRequiresWorkspaceId(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('POST', '/api/administracion/equipos', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['nombre' => 'Support']));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTeamUnknownWorkspaceReturns400(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('POST', '/api/administracion/equipos', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['espacioDeTrabajoId' => 999999, 'nombre' => 'Support']));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Get
    // ────────────────────────────────────────────────────────

    public function testGetTeam(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $this->client->request('GET', "/api/administracion/equipos/{$team->getId()}", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Support', $data['nombre']);
    }

    public function testGetTeamReturns404ForNonExistent(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('GET', '/api/administracion/equipos/99999', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Update
    // ────────────────────────────────────────────────────────

    public function testUpdateTeamName(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Old Name');

        $this->client->request('PUT', "/api/administracion/equipos/{$team->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['nombre' => 'New Name']));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('New Name', $data['nombre']);
    }

    public function testUpdateTeamActiveStatus(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $this->client->request('PUT', "/api/administracion/equipos/{$team->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['activo' => false]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertFalse($data['activo']);
    }

    // ────────────────────────────────────────────────────────
    //  Deactivate (soft delete)
    // ────────────────────────────────────────────────────────

    public function testDeactivateTeamIsSoftDelete(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $this->client->request('DELETE', "/api/administracion/equipos/{$team->getId()}", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());

        $this->em->clear();
        $deactivated = $this->em->getRepository(Equipo::class)->find($team->getId());
        self::assertNotNull($deactivated, 'Team must still exist (soft delete)');
        self::assertFalse($deactivated->isActivo(), 'Team must be deactivated');
    }

    public function testDeactivateTeamRetainsTicketAttribution(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "INSERT INTO tickets (status, priority, created_at, updated_at, version, last_event_seq, team_id) VALUES ('nuevo', 'media', NOW(), NOW(), 0, 0, :teamId)",
            ['teamId' => $team->getId()]
        );
        $ticketId = (int) $conn->lastInsertId();

        $this->client->request('DELETE', "/api/administracion/equipos/{$team->getId()}", [], [], $this->authHeaders($token));
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $ticketTeamId = $conn->executeQuery('SELECT team_id FROM tickets WHERE id = :id', ['id' => $ticketId])->fetchOne();
        self::assertSame($team->getId(), (int) $ticketTeamId, 'Deactivation must not clear ticket team_id attribution');
    }

    public function testDeleteNonExistentTeamReturns404(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');

        $this->client->request('DELETE', '/api/administracion/equipos/99999', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Bulk agent assignment
    // ────────────────────────────────────────────────────────

    public function testAssignAgentsReplacesRoster(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $agentA = $this->createAgent('Agent A');
        $agentA->addEspacioDeTrabajo($workspace);
        $agentB = $this->createAgent('Agent B');
        $agentB->addEspacioDeTrabajo($workspace);
        $this->em->flush();

        $this->client->request('PUT', "/api/administracion/equipos/{$team->getId()}/agentes", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['agenteIds' => [$agentA->getId(), $agentB->getId()]]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertCount(2, $data['agenteIds']);
        self::assertContains($agentA->getId(), $data['agenteIds']);
        self::assertContains($agentB->getId(), $data['agenteIds']);
    }

    public function testAssignAgentsWithOneOutsideWorkspaceReturns400AndWritesNothing(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMINISTRADOR']);
        $token = $this->login('admin@test.local');
        $workspaceA = $this->createWorkspace('+1000000000', 'Workspace A');
        $workspaceB = $this->createWorkspace('+2000000000', 'Workspace B');
        $team = $this->createTeam($workspaceA, 'Support');

        $agentInWorkspace = $this->createAgent('Agent In');
        $agentInWorkspace->addEspacioDeTrabajo($workspaceA);
        $agentOutsideWorkspace = $this->createAgent('Agent Out');
        $agentOutsideWorkspace->addEspacioDeTrabajo($workspaceB);
        $this->em->flush();

        $this->client->request('PUT', "/api/administracion/equipos/{$team->getId()}/agentes", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['agenteIds' => [$agentInWorkspace->getId(), $agentOutsideWorkspace->getId()]]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Zero rows written — all-or-nothing rollback
        $this->em->clear();
        $reloadedTeam = $this->em->getRepository(Equipo::class)->find($team->getId());
        self::assertCount(0, $reloadedTeam->getAgentes(), 'No partial membership must be applied on rejection');
    }
}
