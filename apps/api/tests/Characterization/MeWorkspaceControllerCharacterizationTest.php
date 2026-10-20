<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\Mensaje;

/**
 * Characterization of the workspace actions of App\Administracion\Controller\MiEspacioDeTrabajoController:
 * app_me_workspace, app_me_workspace_set, app_me_workspaces.
 */
final class MeWorkspaceControllerCharacterizationTest extends CharacterizationTestCase
{
    private const ISO_DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    // ── app_me_workspace (GET /api/mi/espacio-de-trabajo) ──

    public function testGetActiveWorkspaceWithoutOneReturns404WithNullFields(): void
    {
        $token = $this->loginAs('me-ws@characterization.local', ['ROLE_AGENTE'], $this->createAgent('Me'));

        $response = $this->request('GET', '/api/mi/espacio-de-trabajo', $token);

        // Characterization: current behavior, not necessarily desired.
        // A data-shaped body with an error status (shape S12 in odd/fase-3/errores.md).
        $this->assertJsonResponse(404, ['espacioDeTrabajoId' => null, 'phoneNumberId' => null, 'nombreVisible' => null], $response);
    }

    public function testGetActiveWorkspaceReturnsIt(): void
    {
        $workspace = $this->createWorkspace('char-me-ws', 'Mine');
        $agent = $this->createAgent('Me');
        $agent->setEspacioDeTrabajoActivo($workspace);
        $token = $this->loginAs('me-ws@characterization.local', ['ROLE_AGENTE'], $agent);

        $response = $this->request('GET', '/api/mi/espacio-de-trabajo', $token);

        $this->assertJsonResponse(200, [
            'espacioDeTrabajoId' => $workspace->getId(),
            'phoneNumberId' => 'char-me-ws',
            'nombreVisible' => 'Mine',
        ], $response);
    }

    public function testGetActiveWorkspaceForUserWithoutAgentReturns404(): void
    {
        $token = $this->loginAs('me-admin@characterization.local', ['ROLE_ADMINISTRADOR']);

        $this->assertJsonResponse(404, ['error' => 'Agent not found'], $this->request('GET', '/api/mi/espacio-de-trabajo', $token));
    }

    // ── app_me_workspace_set (PUT /api/mi/espacio-de-trabajo) ──

    public function testAgentSetsAnAssignedWorkspace(): void
    {
        $workspace = $this->createWorkspace('char-me-set', 'Assigned');
        $agent = $this->createAgent('Me');
        $workspace->addAgente($agent);
        $token = $this->loginAs('me-set@characterization.local', ['ROLE_AGENTE'], $agent);

        $response = $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => $workspace->getId()]);

        $this->assertJsonResponse(200, [
            'espacioDeTrabajoId' => $workspace->getId(),
            'phoneNumberId' => 'char-me-set',
            'nombreVisible' => 'Assigned',
        ], $response);
        self::assertSame($workspace->getId(), (int) $this->connection()->fetchOne('SELECT active_workspace_id FROM agents WHERE id = ?', [$agent->getId()]));
    }

    public function testAgentCannotSetAWorkspaceItIsNotAssignedTo(): void
    {
        $workspace = $this->createWorkspace('char-me-set', 'Other');
        $agent = $this->createAgent('Me');
        $token = $this->loginAs('me-set@characterization.local', ['ROLE_AGENTE'], $agent);

        $response = $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => $workspace->getId()]);

        $this->assertJsonResponse(403, ['error' => 'Agent is not assigned to this workspace'], $response);
        self::assertNull($this->connection()->fetchOne('SELECT active_workspace_id FROM agents WHERE id = ?', [$agent->getId()]));
    }

    public function testAgentRoleUserWithoutAgentGets404(): void
    {
        $workspace = $this->createWorkspace('char-me-set', 'Any');
        $token = $this->loginAs('me-noagent@characterization.local', ['ROLE_AGENTE']);

        $response = $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => $workspace->getId()]);

        $this->assertJsonResponse(404, ['error' => 'Agent not found'], $response);
    }

    public function testAdminWithoutAgentGetsAnAgentCreatedAndAnyWorkspaceSet(): void
    {
        $workspace = $this->createWorkspace('char-me-set', 'Any');
        $token = $this->loginAs('me-admin@characterization.local', ['ROLE_ADMINISTRADOR']);

        $response = $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => $workspace->getId()]);

        $this->assertJsonResponse(200, [
            'espacioDeTrabajoId' => $workspace->getId(),
            'phoneNumberId' => 'char-me-set',
            'nombreVisible' => 'Any',
        ], $response);

        // Characterization: current behavior, not necessarily desired.
        // A read-like settings action creates an Agent row (ensureAgent side-write),
        // named after the user's email.
        $row = $this->connection()->fetchAssociative(
            'SELECT a.name, a.active_workspace_id FROM agents a JOIN `user` u ON u.id = a.user_id WHERE u.email = ?',
            ['me-admin@characterization.local'],
        );
        self::assertNotFalse($row);
        self::assertSame('me-admin@characterization.local', $row['name']);
        self::assertSame($workspace->getId(), (int) $row['active_workspace_id']);
        self::assertSame(1, $this->countRows('agents'));
    }

    public function testSupervisorCanSetAWorkspaceItIsNotAssignedTo(): void
    {
        $workspace = $this->createWorkspace('char-me-set', 'Any');
        $agent = $this->createAgent('Sup');
        $token = $this->loginAs('me-sup@characterization.local', ['ROLE_SUPERVISOR'], $agent);

        $response = $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => $workspace->getId()]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($workspace->getId(), (int) $this->connection()->fetchOne('SELECT active_workspace_id FROM agents WHERE id = ?', [$agent->getId()]));
        self::assertSame(1, $this->countRows('agents'));
    }

    public function testSetActiveWorkspaceErrors(): void
    {
        $inactive = $this->createWorkspace('char-me-inactive', 'Inactive');
        $inactive->setActivo(false);
        $this->em->flush();
        $token = $this->loginAs('me-err@characterization.local', ['ROLE_AGENTE'], $this->createAgent('Me'));

        $this->assertJsonResponse(400, ['error' => 'Invalid JSON body'], $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, '{not json'));
        $this->assertJsonResponse(400, ['error' => 'espacioDeTrabajoId is required and must be an integer'], $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, []));
        $this->assertJsonResponse(400, ['error' => 'espacioDeTrabajoId is required and must be an integer'], $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => '1']));
        $this->assertJsonResponse(404, ['error' => 'Workspace not found'], $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => 999999999]));
        $this->assertJsonResponse(400, ['error' => 'Workspace is not active'], $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => $inactive->getId()]));
    }

    // ── app_me_workspaces (GET /api/mi/espacios-de-trabajo) ──

    public function testAdminListsAllActiveWorkspaces(): void
    {
        $active = $this->createWorkspace('char-me-list-a', 'Active');
        $active->addAgente($this->createAgent('Member'));
        $inactive = $this->createWorkspace('char-me-list-i', 'Inactive');
        $inactive->setActivo(false);
        $this->em->flush();
        $token = $this->loginAs('me-admin@characterization.local', ['ROLE_ADMINISTRADOR']);

        $response = $this->request('GET', '/api/mi/espacios-de-trabajo', $token);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertCount(1, $body);
        self::assertSame(
            ['id', 'phoneNumberId', 'nombreVisible', 'activo', 'creadoAt', 'actualizadoAt', 'cantidadDeAgentes', 'hayPendientes'],
            \array_keys($body[0]),
        );
        self::assertSame($active->getId(), $body[0]['id']);
        self::assertSame('char-me-list-a', $body[0]['phoneNumberId']);
        self::assertSame('Active', $body[0]['nombreVisible']);
        self::assertTrue($body[0]['activo']);
        self::assertMatchesRegularExpression(self::ISO_DATETIME, $body[0]['creadoAt']);
        // Characterization: current behavior, not necessarily desired.
        // agent_count is always 0 and has_pending always false for admins
        // (WorkspaceDTO defaults), even though the workspace has an agent.
        self::assertSame(0, $body[0]['cantidadDeAgentes']);
        self::assertFalse($body[0]['hayPendientes']);
    }

    public function testAgentListsAssignedWorkspacesWithPendingFlag(): void
    {
        $agent = $this->createAgent('Me');
        $pending = $this->createWorkspace('char-me-pend', 'Pending');
        $quiet = $this->createWorkspace('char-me-quiet', 'Quiet');
        $this->createWorkspace('char-me-notmine', 'Not mine');
        $pending->addAgente($agent);
        $quiet->addAgente($agent);
        $this->em->flush();

        // An assigned, never-read ticket in the "pending" workspace.
        $ticket = $this->createTicket('activo', $agent->getId());
        $ticket->setPhoneNumberId('char-me-pend');
        // A read ticket with no newer inbound message in the "quiet" workspace.
        $readTicket = $this->createTicket('activo', $agent->getId());
        $readTicket->setPhoneNumberId('char-me-quiet');
        $readTicket->setUltimaLecturaAt(new \DateTimeImmutable('+1 hour'));
        $message = new Mensaje();
        $message->setConversacion($readTicket->getConversacion());
        $message->setDireccion(DireccionDeMensaje::ENTRANTE->value);
        $message->setContenido('old');
        $this->em->persist($message);
        $this->em->flush();

        $token = $this->loginAs('me-agent@characterization.local', ['ROLE_AGENTE'], $agent);

        $response = $this->request('GET', '/api/mi/espacios-de-trabajo', $token);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $byPhone = \array_column($body, null, 'phoneNumberId');
        self::assertEqualsCanonicalizing(['char-me-pend', 'char-me-quiet'], \array_keys($byPhone));
        self::assertTrue($byPhone['char-me-pend']['hayPendientes']);
        self::assertFalse($byPhone['char-me-quiet']['hayPendientes']);
        self::assertSame(0, $byPhone['char-me-pend']['cantidadDeAgentes']);
        self::assertSame(
            ['id', 'phoneNumberId', 'nombreVisible', 'activo', 'creadoAt', 'actualizadoAt', 'cantidadDeAgentes', 'hayPendientes'],
            \array_keys($body[0]),
        );
    }

    public function testAgentRoleUserWithoutAgentGetsAnEmptyList(): void
    {
        $this->createWorkspace('char-me-list', 'Any');
        $token = $this->loginAs('me-noagent@characterization.local', ['ROLE_AGENTE']);

        $this->assertJsonResponse(200, [], $this->request('GET', '/api/mi/espacios-de-trabajo', $token));
    }
}
