<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Agente\Team;

/**
 * Edge cases of the Administracion controllers that moving their writes into
 * use cases could change silently: check order, non-object JSON bodies and
 * transaction rollback.
 */
final class AdministracionEdgeCasesCharacterizationTest extends CharacterizationTestCase
{
    private function adminToken(): string
    {
        return $this->loginAs('edge-admin@characterization.local', ['ROLE_ADMIN']);
    }

    public function testCreateUserChecksDuplicateEmailBeforeTheAgent(): void
    {
        $this->createUser('taken@characterization.local', ['ROLE_AGENT']);
        $token = $this->adminToken();

        $response = $this->request('POST', '/api/admin/users', $token, [
            'email' => 'taken@characterization.local',
            'password' => 'secret',
            'agent_id' => 999999999,
        ]);

        $this->assertJsonResponse(409, ['error' => 'Email already exists'], $response);
    }

    public function testCreateUserWithJsonNullBodyIsAMissingFieldsError(): void
    {
        $token = $this->adminToken();

        $response = $this->request('POST', '/api/admin/users', $token, 'null');

        $this->assertJsonResponse(400, ['error' => 'email and password are required'], $response);
    }

    public function testUpdateUserChecksDuplicateEmailBeforeThePassword(): void
    {
        $this->createUser('other@characterization.local', ['ROLE_AGENT']);
        $target = $this->createUser('target@characterization.local', ['ROLE_AGENT']);
        $token = $this->adminToken();

        $response = $this->request('PUT', '/api/admin/users/' . $target->getId(), $token, [
            'email' => 'other@characterization.local',
            'password' => 123,
        ]);

        $this->assertJsonResponse(409, ['error' => 'Email already in use'], $response);
        self::assertSame('target@characterization.local', $this->connection()->fetchOne('SELECT email FROM `user` WHERE id = ?', [$target->getId()]));
    }

    public function testUpdateUnknownUserWithInvalidJsonIsNotFound(): void
    {
        $token = $this->adminToken();

        $response = $this->request('PUT', '/api/admin/users/999999999', $token, '{not json');

        $this->assertJsonResponse(404, ['error' => 'User not found'], $response);
    }

    public function testUpdateUserStopsAtTheFirstInvalidFieldWithoutSaving(): void
    {
        $target = $this->createUser('partial@characterization.local', ['ROLE_AGENT']);
        $token = $this->adminToken();

        $response = $this->request('PUT', '/api/admin/users/' . $target->getId(), $token, [
            'email' => 'renamed@characterization.local',
            'roles' => 'ROLE_ADMIN',
        ]);

        $this->assertJsonResponse(400, ['error' => 'roles must be an array'], $response);
        self::assertSame('partial@characterization.local', $this->connection()->fetchOne('SELECT email FROM `user` WHERE id = ?', [$target->getId()]));
    }

    public function testLinkUnknownUserIsNotFound(): void
    {
        $agent = $this->createAgent('Linkable');
        $token = $this->adminToken();

        $response = $this->request('POST', '/api/admin/agents/' . $agent->getId() . '/link-user', $token, ['user_id' => 999999999]);

        $this->assertJsonResponse(404, ['error' => 'User not found'], $response);
    }

    public function testLinkChecksDeactivatedUserBeforeAnExistingLink(): void
    {
        $agent = $this->createAgent('Linked');
        $this->createUser('owner@characterization.local', ['ROLE_AGENT'], $agent);
        $inactive = $this->createUser('inactive@characterization.local', ['ROLE_AGENT']);
        $inactive->setIsActive(false);
        $this->em->flush();
        $token = $this->adminToken();

        $response = $this->request('POST', '/api/admin/agents/' . $agent->getId() . '/link-user', $token, ['user_id' => $inactive->getId()]);

        $this->assertJsonResponse(400, ['error' => 'Cannot link a deactivated user to an agent'], $response);
    }

    public function testUnlinkAgentWithoutUserIsABadRequest(): void
    {
        $agent = $this->createAgent('Lonely');
        $token = $this->adminToken();

        $response = $this->request('DELETE', '/api/admin/agents/' . $agent->getId() . '/link-user', $token);

        $this->assertJsonResponse(400, ['error' => 'Agent has no linked user'], $response);
    }

    public function testCreateTeamInUnknownWorkspaceIsABadRequest(): void
    {
        $token = $this->adminToken();

        $response = $this->request('POST', '/api/admin/teams', $token, ['workspaceId' => 999999999, 'name' => 'Ghost']);

        // Characterization: current behavior, not necessarily desired.
        // An unknown workspace is a 400, not a 404.
        $this->assertJsonResponse(400, ['error' => 'Workspace not found'], $response);
    }

    public function testAssignTeamAgentsRollsBackOnANonIntegerId(): void
    {
        $workspace = $this->createWorkspace('edge-ws-1');
        $kept = $this->createAgent('Kept');
        $workspace->addAgent($kept);
        $team = new Team($workspace, 'Edge team');
        $team->addAgent($kept);
        $this->em->persist($team);
        $this->em->flush();
        $token = $this->adminToken();

        $response = $this->request('PUT', '/api/admin/teams/' . $team->getId() . '/agents', $token, ['agentIds' => ['x']]);

        $this->assertJsonResponse(400, ['error' => 'agentIds must contain only integers'], $response);
        self::assertSame(
            [(string) $kept->getId()],
            \array_map('strval', $this->connection()->fetchFirstColumn('SELECT agent_id FROM agent_team WHERE team_id = ?', [$team->getId()])),
        );
    }

    public function testAssignWorkspaceAgentsIgnoresUnknownIds(): void
    {
        $workspace = $this->createWorkspace('edge-ws-2');
        $agent = $this->createAgent('Known');
        $token = $this->adminToken();

        $response = $this->request('PUT', '/api/admin/workspaces/' . $workspace->getId() . '/agents', $token, ['agentIds' => [$agent->getId(), 999999999]]);

        $this->assertJsonResponse(200, ['workspace_id' => $workspace->getId(), 'agent_ids' => [$agent->getId()]], $response);
    }
}
