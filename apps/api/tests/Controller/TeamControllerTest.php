<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Agente\Agente;
use App\Agente\Equipo;
use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for `GET /api/teams` (Tier 2 #7 — Team/Area Entity,
 * Slice 1). Non-admin, workspace-scoped listing for any authenticated
 * agent — built now as part of Slice 1's API surface for the future inbox
 * filter dropdown (Slice 3).
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

    private function createUser(string $email, array $roles = ['ROLE_AGENT'], ?Agente $agent = null): Usuario
    {
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

    private function createWorkspace(string $phoneNumberId, string $displayName): EspacioDeTrabajo
    {
        $workspace = new EspacioDeTrabajo($phoneNumberId, $displayName);
        $this->em->persist($workspace);
        $this->em->flush();

        return $workspace;
    }

    private function createTeam(EspacioDeTrabajo $workspace, string $name, bool $active = true): Equipo
    {
        $team = new Equipo($workspace, $name);
        $team->setActivo($active);
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
    //  Tests
    // ────────────────────────────────────────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->client->request('GET', '/api/teams');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testPlainAgentSeesOnlyOwnWorkspaceTeams(): void
    {
        $workspaceA = $this->createWorkspace('+1000000000', 'Workspace A');
        $workspaceB = $this->createWorkspace('+2000000000', 'Workspace B');

        $teamA = $this->createTeam($workspaceA, 'Support A');
        $this->createTeam($workspaceB, 'Support B'); // must NOT appear

        $agent = $this->createAgent('Agent A');
        $agent->addEspacioDeTrabajo($workspaceA);
        $this->createUser('agent@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent@test.local');

        $this->client->request('GET', '/api/teams', [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame($teamA->getId(), $data[0]['id']);
        self::assertSame('Support A', $data[0]['name']);
    }

    public function testAgentWithoutWorkspaceGetsEmptyArray(): void
    {
        $workspace = $this->createWorkspace('+1000000000', 'Workspace A');
        $this->createTeam($workspace, 'Support');

        $agent = $this->createAgent('Unassigned Agent');
        $this->createUser('unassigned@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('unassigned@test.local');

        $this->client->request('GET', '/api/teams', [], [], $this->authHeaders($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $data);
    }

    public function testDeactivatedTeamStillReturnedForFiltering(): void
    {
        $workspace = $this->createWorkspace('+1000000000', 'Workspace A');
        $team = $this->createTeam($workspace, 'Legacy Team', active: false);

        $agent = $this->createAgent('Agent A');
        $agent->addEspacioDeTrabajo($workspace);
        $this->createUser('agent2@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent2@test.local');

        $this->client->request('GET', '/api/teams', [], [], $this->authHeaders($token));
        $data = \json_decode($this->client->getResponse()->getContent(), true);

        self::assertCount(1, $data);
        self::assertSame($team->getId(), $data[0]['id']);
        self::assertFalse($data[0]['is_active']);
    }
}
