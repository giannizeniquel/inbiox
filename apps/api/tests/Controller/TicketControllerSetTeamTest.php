<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Agente\Equipo;
use App\Ticket\Ticket;
use App\Administracion\User;
use App\Administracion\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for `PUT /api/tickets/{id}/team` (Tier 2 #7 — Team/Area
 * Entity, Slice 1).
 *
 * Covers team-management spec scenarios:
 * - Agent assigns ticket to team in same workspace — success.
 * - Cross-workspace team assignment — 400, team_id unchanged.
 * - Ticket team cleared to null by any agent.
 * Any authenticated agent (any role) MAY set/clear — same permission level
 * as tag assignment, no Supervisor/Admin restriction (design.md D5).
 */
class TicketControllerSetTeamTest extends WebTestCase
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
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM teams');
        $conn->executeStatement('DELETE FROM agent_workspace');
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

    private function createAgentUser(string $email): string
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_AGENT']);
        $this->em->persist($user);

        $agent = new Agente('Agent for ' . $email);
        $agent->setUser($user);
        $this->em->persist($agent);
        $this->em->flush();

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

    private function createWorkspace(string $phoneNumberId, string $displayName): Workspace
    {
        $workspace = new Workspace($phoneNumberId, $displayName);
        $this->em->persist($workspace);
        $this->em->flush();

        return $workspace;
    }

    private function createTeam(Workspace $workspace, string $name = 'Support'): Equipo
    {
        $team = new Equipo($workspace, $name);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createTicket(?string $phoneNumberId = null): Ticket
    {
        $contact = new Contacto();
        $contact->setTelefono('+549110000' . random_int(1000, 9999));
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setPhoneNumberId($phoneNumberId);
        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }

    // ────────────────────────────────────────────────────────
    //  Tests
    // ────────────────────────────────────────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $ticket = $this->createTicket();

        $this->client->request('PUT', "/api/tickets/{$ticket->getId()}/team", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['teamId' => null]));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testSetTeamSameWorkspaceSucceeds(): void
    {
        $token = $this->createAgentUser('agent@test.local');
        $workspace = $this->createWorkspace('+1000000000', 'Workspace A');
        $team = $this->createTeam($workspace, 'Support');
        $ticket = $this->createTicket('+1000000000');

        $this->client->request('PUT', "/api/tickets/{$ticket->getId()}/team", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['teamId' => $team->getId()]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame($team->getId(), $data['team_id']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame($team->getId(), $reloaded->getEquipoId());
    }

    public function testSetTeamCrossWorkspaceReturns400AndLeavesTeamIdUnchanged(): void
    {
        $token = $this->createAgentUser('agent2@test.local');
        $workspaceA = $this->createWorkspace('+1000000000', 'Workspace A');
        $workspaceB = $this->createWorkspace('+2000000000', 'Workspace B');
        $team = $this->createTeam($workspaceB, 'Support');
        $ticket = $this->createTicket('+1000000000'); // ticket belongs to workspace A

        $this->client->request('PUT', "/api/tickets/{$ticket->getId()}/team", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['teamId' => $team->getId()]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertNull($reloaded->getEquipoId(), 'team_id must remain unchanged on cross-workspace rejection');
    }

    public function testClearTeamToNullSucceeds(): void
    {
        $token = $this->createAgentUser('agent3@test.local');
        $workspace = $this->createWorkspace('+1000000000', 'Workspace A');
        $team = $this->createTeam($workspace, 'Support');
        $ticket = $this->createTicket('+1000000000');
        $ticket->setEquipo($team);
        $this->em->flush();

        $this->client->request('PUT', "/api/tickets/{$ticket->getId()}/team", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['teamId' => null]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertNull($data['team_id']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertNull($reloaded->getEquipoId());
    }

    public function testSetTeamUnknownTicketReturns404(): void
    {
        $token = $this->createAgentUser('agent4@test.local');

        $this->client->request('PUT', '/api/tickets/99999/team', [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['teamId' => null]));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testSetTeamUnknownTeamIdReturns400(): void
    {
        $token = $this->createAgentUser('agent5@test.local');
        $ticket = $this->createTicket('+1000000000');

        $this->client->request('PUT', "/api/tickets/{$ticket->getId()}/team", [], [], $this->authHeaders($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['teamId' => 999999]));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }
}
