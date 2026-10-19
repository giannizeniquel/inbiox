<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

/**
 * Characterization of App\Administracion\Controller\GestionDeEspaciosDeTrabajoController
 * (app_admin_workspaces_*). Admin-only via access_control and #[IsGranted].
 */
final class WorkspaceControllerCharacterizationTest extends CharacterizationTestCase
{
    private const ISO_DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    private const WORKSPACE_KEYS = [
        'id', 'phone_number_id', 'display_name', 'is_active',
        'agent_count', 'agent_ids', 'created_at', 'updated_at',
    ];

    private function adminToken(): string
    {
        return $this->loginAs('ws-admin@characterization.local', ['ROLE_ADMIN']);
    }

    private function assertWorkspaceBody(array $body, int $id, string $phone, string $name, bool $active, array $agentIds): void
    {
        self::assertSame(self::WORKSPACE_KEYS, \array_keys($body));
        self::assertSame($id, $body['id']);
        self::assertSame($phone, $body['phone_number_id']);
        self::assertSame($name, $body['display_name']);
        self::assertSame($active, $body['is_active']);
        self::assertSame(\count($agentIds), $body['agent_count']);
        self::assertSame($agentIds, $body['agent_ids']);
        self::assertMatchesRegularExpression(self::ISO_DATETIME, $body['created_at']);
        self::assertMatchesRegularExpression(self::ISO_DATETIME, $body['updated_at']);
    }

    // ── Access ──

    public function testAgentAndSupervisorAreForbidden(): void
    {
        $agentToken = $this->loginAs('ws-agent@characterization.local', ['ROLE_AGENT'], $this->createAgent('A'));
        $this->assertSecurityForbidden($this->request('GET', '/api/admin/workspaces', $agentToken));

        $supervisorToken = $this->loginAs('ws-sup@characterization.local', ['ROLE_SUPERVISOR']);
        $this->assertSecurityForbidden($this->request('GET', '/api/admin/workspaces', $supervisorToken));
        $this->assertSecurityForbidden($this->request('POST', '/api/admin/workspaces', $supervisorToken, ['phoneNumberId' => 'x', 'displayName' => 'y']));
        self::assertSame(0, $this->countRows('workspaces'));
    }

    // ── app_admin_workspaces_list ──

    public function testListReturnsWorkspacesNewestFirstWithPaginationMeta(): void
    {
        $first = $this->createWorkspace('char-ws-1', 'First');
        $second = $this->createWorkspace('char-ws-2', 'Second');
        $agent = $this->createAgent('Member');
        $second->addAgent($agent);
        $this->em->flush();
        $token = $this->adminToken();

        $response = $this->request('GET', '/api/admin/workspaces', $token);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(['data', 'meta'], \array_keys($body));
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 2, 'has_next' => false], $body['meta']);
        self::assertCount(2, $body['data']);
        $this->assertWorkspaceBody($body['data'][0], $second->getId(), 'char-ws-2', 'Second', true, [$agent->getId()]);
        $this->assertWorkspaceBody($body['data'][1], $first->getId(), 'char-ws-1', 'First', true, []);
    }

    public function testListPagesAndClampsLimit(): void
    {
        $this->createWorkspace('char-ws-1', 'First');
        $second = $this->createWorkspace('char-ws-2', 'Second');
        $token = $this->adminToken();

        $body = $this->json($this->request('GET', '/api/admin/workspaces?page=1&limit=1', $token));
        self::assertSame(['page' => 1, 'limit' => 1, 'total' => 2, 'has_next' => true], $body['meta']);
        self::assertSame([$second->getId()], \array_column($body['data'], 'id'));

        $body = $this->json($this->request('GET', '/api/admin/workspaces?page=0&limit=500', $token));
        self::assertSame(['page' => 1, 'limit' => 100, 'total' => 2, 'has_next' => false], $body['meta']);
    }

    // ── app_admin_workspaces_create ──

    public function testCreatePersistsWorkspaceAndReturns201(): void
    {
        $token = $this->adminToken();

        $response = $this->request('POST', '/api/admin/workspaces', $token, [
            'phoneNumberId' => 'char-new',
            'displayName' => 'New WS',
            'isActive' => false,
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        $row = $this->connection()->fetchAssociative('SELECT id, display_name, is_active FROM workspaces WHERE phone_number_id = ?', ['char-new']);
        self::assertNotFalse($row);
        $this->assertWorkspaceBody($body, (int) $row['id'], 'char-new', 'New WS', false, []);
        self::assertSame('New WS', $row['display_name']);
        self::assertSame(0, (int) $row['is_active']);
        self::assertSame(0, $this->countRows('outbox_events'));
    }

    public function testCreateDefaultsToActive(): void
    {
        $token = $this->adminToken();

        $body = $this->json($this->request('POST', '/api/admin/workspaces', $token, ['phoneNumberId' => 'char-new', 'displayName' => 'New WS']));

        self::assertTrue($body['is_active']);
    }

    public function testCreateValidationErrors(): void
    {
        $token = $this->adminToken();

        $this->assertJsonResponse(400, ['error' => 'Invalid JSON body'], $this->request('POST', '/api/admin/workspaces', $token, '{not json'));
        $this->assertJsonResponse(400, ['error' => 'phoneNumberId is required and must be a string'], $this->request('POST', '/api/admin/workspaces', $token, ['displayName' => 'X']));
        $this->assertJsonResponse(400, ['error' => 'phoneNumberId is required and must be a string'], $this->request('POST', '/api/admin/workspaces', $token, ['phoneNumberId' => 123, 'displayName' => 'X']));
        $this->assertJsonResponse(400, ['error' => 'displayName is required and must be a string'], $this->request('POST', '/api/admin/workspaces', $token, ['phoneNumberId' => 'char-x']));
        self::assertSame(0, $this->countRows('workspaces'));
    }

    public function testCreateDuplicatePhoneNumberIdReturns409(): void
    {
        $this->createWorkspace('char-dup', 'Existing');
        $token = $this->adminToken();

        $response = $this->request('POST', '/api/admin/workspaces', $token, ['phoneNumberId' => 'char-dup', 'displayName' => 'Other']);

        $this->assertJsonResponse(409, ['error' => 'A workspace with this phone number ID already exists'], $response);
        self::assertSame(1, $this->countRows('workspaces'));
    }

    // ── app_admin_workspaces_get ──

    public function testGetReturnsWorkspace(): void
    {
        $workspace = $this->createWorkspace('char-get', 'Get Me');
        $token = $this->adminToken();

        $response = $this->request('GET', '/api/admin/workspaces/' . $workspace->getId(), $token);

        self::assertSame(200, $response->getStatusCode());
        $this->assertWorkspaceBody($this->json($response), $workspace->getId(), 'char-get', 'Get Me', true, []);
    }

    public function testGetUnknownWorkspaceReturns404(): void
    {
        $token = $this->adminToken();

        $this->assertJsonResponse(404, ['error' => 'Workspace not found'], $this->request('GET', '/api/admin/workspaces/999999999', $token));
    }

    // ── app_admin_workspaces_update ──

    public function testUpdateChangesFieldsAndPersists(): void
    {
        $workspace = $this->createWorkspace('char-upd', 'Before');
        $token = $this->adminToken();

        $response = $this->request('PUT', '/api/admin/workspaces/' . $workspace->getId(), $token, [
            'phoneNumberId' => 'char-upd-2',
            'displayName' => 'After',
            'isActive' => false,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertWorkspaceBody($this->json($response), $workspace->getId(), 'char-upd-2', 'After', false, []);
        $row = $this->connection()->fetchAssociative('SELECT phone_number_id, display_name, is_active FROM workspaces WHERE id = ?', [$workspace->getId()]);
        self::assertSame(['phone_number_id' => 'char-upd-2', 'display_name' => 'After', 'is_active' => 0], [
            'phone_number_id' => $row['phone_number_id'],
            'display_name' => $row['display_name'],
            'is_active' => (int) $row['is_active'],
        ]);
    }

    public function testUpdateErrors(): void
    {
        $workspace = $this->createWorkspace('char-upd', 'Before');
        $token = $this->adminToken();
        $uri = '/api/admin/workspaces/' . $workspace->getId();

        $this->assertJsonResponse(404, ['error' => 'Workspace not found'], $this->request('PUT', '/api/admin/workspaces/999999999', $token, ['displayName' => 'X']));
        $this->assertJsonResponse(400, ['error' => 'Invalid JSON body'], $this->request('PUT', $uri, $token, '{not json'));
        $this->assertJsonResponse(400, ['error' => 'phoneNumberId must be a non-empty string'], $this->request('PUT', $uri, $token, ['phoneNumberId' => 42]));
        $this->assertJsonResponse(400, ['error' => 'displayName must be a non-empty string'], $this->request('PUT', $uri, $token, ['displayName' => '']));

        self::assertSame('Before', $this->connection()->fetchOne('SELECT display_name FROM workspaces WHERE id = ?', [$workspace->getId()]));
    }

    // ── app_admin_workspaces_delete ──

    public function testDeleteSoftDeactivatesAndReturns204(): void
    {
        $workspace = $this->createWorkspace('char-del', 'Delete Me');
        $token = $this->adminToken();

        $response = $this->request('DELETE', '/api/admin/workspaces/' . $workspace->getId(), $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT is_active FROM workspaces WHERE id = ?', [$workspace->getId()]));
        self::assertSame(1, $this->countRows('workspaces'));
    }

    public function testDeleteUnknownWorkspaceReturns404(): void
    {
        $token = $this->adminToken();

        $this->assertJsonResponse(404, ['error' => 'Workspace not found'], $this->request('DELETE', '/api/admin/workspaces/999999999', $token));
    }

    // ── app_admin_workspaces_assign_agents ──

    public function testAssignAgentsReplacesTheAgentSetAndIgnoresUnknownIds(): void
    {
        $workspace = $this->createWorkspace('char-assign', 'Assign');
        $old = $this->createAgent('Old');
        $new1 = $this->createAgent('New 1');
        $new2 = $this->createAgent('New 2');
        $workspace->addAgent($old);
        $this->em->flush();
        $token = $this->adminToken();

        $response = $this->request('PUT', '/api/admin/workspaces/' . $workspace->getId() . '/agents', $token, [
            'agentIds' => [$new1->getId(), 999999999, $new2->getId()],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(['workspace_id', 'agent_ids'], \array_keys($body));
        self::assertSame($workspace->getId(), $body['workspace_id']);
        self::assertEqualsCanonicalizing([$new1->getId(), $new2->getId()], $body['agent_ids']);

        $stored = \array_map('intval', $this->connection()->fetchFirstColumn('SELECT agent_id FROM agent_workspace WHERE workspace_id = ?', [$workspace->getId()]));
        self::assertEqualsCanonicalizing([$new1->getId(), $new2->getId()], $stored);
    }

    public function testAssignAgentsWithEmptyBodyListClearsAgents(): void
    {
        $workspace = $this->createWorkspace('char-assign', 'Assign');
        $workspace->addAgent($this->createAgent('Old'));
        $this->em->flush();
        $token = $this->adminToken();

        // A missing agentIds key behaves like an empty list.
        $response = $this->request('PUT', '/api/admin/workspaces/' . $workspace->getId() . '/agents', $token, []);

        $this->assertJsonResponse(200, ['workspace_id' => $workspace->getId(), 'agent_ids' => []], $response);
        self::assertSame(0, $this->countRows('agent_workspace'));
    }

    public function testAssignAgentsErrors(): void
    {
        $workspace = $this->createWorkspace('char-assign', 'Assign');
        $old = $this->createAgent('Old');
        $workspace->addAgent($old);
        $this->em->flush();
        $token = $this->adminToken();
        $uri = '/api/admin/workspaces/' . $workspace->getId() . '/agents';

        $this->assertJsonResponse(404, ['error' => 'Workspace not found'], $this->request('PUT', '/api/admin/workspaces/999999999/agents', $token, ['agentIds' => []]));
        $this->assertJsonResponse(400, ['error' => 'Invalid JSON body'], $this->request('PUT', $uri, $token, '{not json'));
        $this->assertJsonResponse(400, ['error' => 'agentIds must be an array of integers'], $this->request('PUT', $uri, $token, ['agentIds' => 'x']));
        $this->assertJsonResponse(400, ['error' => 'agentIds must contain only integers'], $this->request('PUT', $uri, $token, ['agentIds' => ['1']]));

        // The transaction rolled back: the previous agent set is intact.
        $stored = \array_map('intval', $this->connection()->fetchFirstColumn('SELECT agent_id FROM agent_workspace WHERE workspace_id = ?', [$workspace->getId()]));
        self::assertSame([$old->getId()], $stored);
    }
}
