<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Agente\Agent;
use App\Agente\Team;
use App\Administracion\User;
use App\Administracion\Workspace;
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

    private function createAgent(string $name = 'Test Agent'): Agent
    {
        $agent = new Agent($name);
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function createWorkspace(string $phoneNumberId = '+1000000000', string $displayName = 'Workspace A'): Workspace
    {
        $workspace = new Workspace($phoneNumberId, $displayName);
        $this->em->persist($workspace);
        $this->em->flush();

        return $workspace;
    }

    private function createTeam(Workspace $workspace, string $name = 'Support'): Team
    {
        $team = new Team($workspace, $name);
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
        $this->client->request('GET', '/api/admin/teams');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testListTeamsDeniedForPlainAgent(): void
    {
        $agent = $this->createAgent('Agent');
        $this->createUser('agent@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/admin/teams', [], [], $this->authHeaders($token));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testListTeamsAllowedForSupervisor(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $this->client->request('GET', '/api/admin/teams', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testListTeamsAllowedForAdmin(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $this->client->request('GET', '/api/admin/teams', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  List
    // ────────────────────────────────────────────────────────

    public function testListTeamsReturnsPaginatedResults(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $workspace = $this->createWorkspace();
        for ($i = 1; $i <= 3; $i++) {
            $this->createTeam($workspace, "Team {$i}");
        }

        $this->client->request('GET', '/api/admin/teams?page=1&limit=2', [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('data', $data);
        self::assertArrayHasKey('meta', $data);
        self::assertCount(2, $data['data']);
        self::assertTrue($data['meta']['has_next']);
        self::assertSame(3, $data['meta']['total']);
    }

    // ────────────────────────────────────────────────────────
    //  Create
    // ────────────────────────────────────────────────────────

    public function testCreateTeam(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();

        $this->client->request('POST', '/api/admin/teams', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'workspaceId' => $workspace->getId(),
            'name' => 'Support',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Support', $data['name']);
        self::assertTrue($data['is_active']);
        self::assertSame($workspace->getId(), $data['workspace_id']);
    }

    public function testCreateTeamDuplicateNameSameWorkspaceReturns409(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $this->createTeam($workspace, 'Support');

        $this->client->request('POST', '/api/admin/teams', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'workspaceId' => $workspace->getId(),
            'name' => 'Support',
        ]));

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTeamSameNameDifferentWorkspaceSucceeds(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspaceA = $this->createWorkspace('+1000000000', 'Workspace A');
        $workspaceB = $this->createWorkspace('+2000000000', 'Workspace B');
        $this->createTeam($workspaceA, 'Support');

        $this->client->request('POST', '/api/admin/teams', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'workspaceId' => $workspaceB->getId(),
            'name' => 'Support',
        ]));

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTeamRequiresWorkspaceId(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $this->client->request('POST', '/api/admin/teams', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['name' => 'Support']));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTeamUnknownWorkspaceReturns400(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $this->client->request('POST', '/api/admin/teams', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['workspaceId' => 999999, 'name' => 'Support']));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Get
    // ────────────────────────────────────────────────────────

    public function testGetTeam(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $this->client->request('GET', "/api/admin/teams/{$team->getId()}", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Support', $data['name']);
    }

    public function testGetTeamReturns404ForNonExistent(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $this->client->request('GET', '/api/admin/teams/99999', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Update
    // ────────────────────────────────────────────────────────

    public function testUpdateTeamName(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Old Name');

        $this->client->request('PUT', "/api/admin/teams/{$team->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['name' => 'New Name']));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('New Name', $data['name']);
    }

    public function testUpdateTeamActiveStatus(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $this->client->request('PUT', "/api/admin/teams/{$team->getId()}", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['isActive' => false]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertFalse($data['is_active']);
    }

    // ────────────────────────────────────────────────────────
    //  Deactivate (soft delete)
    // ────────────────────────────────────────────────────────

    public function testDeactivateTeamIsSoftDelete(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $this->client->request('DELETE', "/api/admin/teams/{$team->getId()}", [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());

        $this->em->clear();
        $deactivated = $this->em->getRepository(Team::class)->find($team->getId());
        self::assertNotNull($deactivated, 'Team must still exist (soft delete)');
        self::assertFalse($deactivated->isActive(), 'Team must be deactivated');
    }

    public function testDeactivateTeamRetainsTicketAttribution(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "INSERT INTO tickets (status, priority, created_at, updated_at, version, last_event_seq, team_id) VALUES ('new', 'medium', NOW(), NOW(), 0, 0, :teamId)",
            ['teamId' => $team->getId()]
        );
        $ticketId = (int) $conn->lastInsertId();

        $this->client->request('DELETE', "/api/admin/teams/{$team->getId()}", [], [], $this->authHeaders($token));
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $ticketTeamId = $conn->executeQuery('SELECT team_id FROM tickets WHERE id = :id', ['id' => $ticketId])->fetchOne();
        self::assertSame($team->getId(), (int) $ticketTeamId, 'Deactivation must not clear ticket team_id attribution');
    }

    public function testDeleteNonExistentTeamReturns404(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');

        $this->client->request('DELETE', '/api/admin/teams/99999', [], [], $this->authHeaders($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Bulk agent assignment
    // ────────────────────────────────────────────────────────

    public function testAssignAgentsReplacesRoster(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspace = $this->createWorkspace();
        $team = $this->createTeam($workspace, 'Support');

        $agentA = $this->createAgent('Agent A');
        $agentA->addWorkspace($workspace);
        $agentB = $this->createAgent('Agent B');
        $agentB->addWorkspace($workspace);
        $this->em->flush();

        $this->client->request('PUT', "/api/admin/teams/{$team->getId()}/agents", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['agentIds' => [$agentA->getId(), $agentB->getId()]]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertCount(2, $data['agent_ids']);
        self::assertContains($agentA->getId(), $data['agent_ids']);
        self::assertContains($agentB->getId(), $data['agent_ids']);
    }

    public function testAssignAgentsWithOneOutsideWorkspaceReturns400AndWritesNothing(): void
    {
        $this->createUser('admin@test.local', ['ROLE_ADMIN']);
        $token = $this->login('admin@test.local');
        $workspaceA = $this->createWorkspace('+1000000000', 'Workspace A');
        $workspaceB = $this->createWorkspace('+2000000000', 'Workspace B');
        $team = $this->createTeam($workspaceA, 'Support');

        $agentInWorkspace = $this->createAgent('Agent In');
        $agentInWorkspace->addWorkspace($workspaceA);
        $agentOutsideWorkspace = $this->createAgent('Agent Out');
        $agentOutsideWorkspace->addWorkspace($workspaceB);
        $this->em->flush();

        $this->client->request('PUT', "/api/admin/teams/{$team->getId()}/agents", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['agentIds' => [$agentInWorkspace->getId(), $agentOutsideWorkspace->getId()]]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Zero rows written — all-or-nothing rollback
        $this->em->clear();
        $reloadedTeam = $this->em->getRepository(Team::class)->find($team->getId());
        self::assertCount(0, $reloadedTeam->getAgents(), 'No partial membership must be applied on rejection');
    }
}
