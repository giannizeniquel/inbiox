<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agente\Agente;
use App\Disponibilidad\Turno;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level integration tests for Sprint 10 Agent Management endpoints.
 *
 * Covers DisponibilidadDeAgenteController's 5 endpoints:
 *   POST   /api/agentes/{id}/disponibilidad
 *   DELETE /api/agentes/{id}/disponibilidad
 *   GET    /api/agentes/{id}/disponibilidad
 *   POST   /api/agentes/{id}/turnos
 *   DELETE /api/agentes/{id}/turnos/{turnoId}
 *
 * Tests run against the real test database via Symfony's test client.
 * All tests authenticate as SUPERVISOR to bypass ownership scoping.
 */
class Sprint10ControllerTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;
    private string $authToken = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Clean all relevant tables (order respects FK constraints)
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM rule_execution_logs');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM agent_shifts');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');

        // Create an admin user and authenticate
        $this->authToken = $this->createAuthenticatedUser();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    // ────────────────────────────────────────────────────────
    //  Auth Helpers
    // ────────────────────────────────────────────────────────

    /**
     * Create an admin user (for bypassing ownership checks) and return JWT.
     */
    private function createAuthenticatedUser(): string
    {
        $user = new Usuario();
        $user->setEmail('sprint10-admin@inbiox.local');
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_SUPERVISOR']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'sprint10-admin@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        return $data['access_token'];
    }

    /**
     * Create a basic test agent and return its ID.
     */
    private function createAgent(
        string $name = 'Test Agent',
        string $timezone = 'UTC',
        int $maxActiveTickets = 5,
    ): int {
        $agent = new Agente($name);
        $agent->setZonaHoraria($timezone);
        $agent->setMaxTicketsActivos($maxActiveTickets);
        $this->em->persist($agent);
        $this->em->flush();

        $id = $agent->getId();
        \assert($id !== null);

        return $id;
    }

    /**
     * Shorthand: POST JSON and return decoded response.
     */
    private function postJson(string $uri, array $data): array
    {
        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
            ],
            json_encode($data, JSON_THROW_ON_ERROR),
        );

        $content = $this->client->getResponse()->getContent();

        return $content !== false && $content !== '' ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /**
     * Shorthand: DELETE and return decoded response.
     */
    private function deleteRequest(string $uri): array
    {
        $this->client->request('DELETE', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $content = $this->client->getResponse()->getContent();

        return $content !== false && $content !== '' ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /**
     * Shorthand: GET and return decoded response.
     */
    private function getJson(string $uri): array
    {
        $this->client->request('GET', $uri, [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $content = $this->client->getResponse()->getContent();

        return $content !== false && $content !== '' ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    // ────────────────────────────────────────────────────────
    //  POST /api/agentes/{id}/disponibilidad
    // ────────────────────────────────────────────────────────

    public function testSetAvailabilityValid(): void
    {
        $agentId = $this->createAgent();

        $data = $this->postJson("/api/agentes/{$agentId}/disponibilidad", [
            'disponible' => false,
            'ttlMinutos' => 120,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(['agenteId', 'disponible', 'disponibilidadManualActiva', 'cargaActual', 'capacidadMaxima'], array_keys($data));
        $this->assertFalse($data['disponible']);
        $this->assertTrue($data['disponibilidadManualActiva']);
    }

    public function testSetAvailabilityInvalidAgent(): void
    {
        $this->postJson('/api/agentes/99999/disponibilidad', [
            'disponible' => false,
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertStringContainsString('not found', $this->client->getResponse()->getContent() ?: '');
    }

    public function testSetAvailabilityMissingFields(): void
    {
        $agentId = $this->createAgent();

        $this->postJson("/api/agentes/{$agentId}/disponibilidad", []);

        // Sin 'disponible' responde 422 (la validación de Symfony está deshabilitada: lo corta el controlador)
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['error' => 'disponible is required'], json_decode($this->client->getResponse()->getContent() ?: '', true));
    }

    // ────────────────────────────────────────────────────────
    //  DELETE /api/agentes/{id}/disponibilidad
    // ────────────────────────────────────────────────────────

    public function testClearAvailabilityExists(): void
    {
        $agentId = $this->createAgent();

        // First set an override so there's something to clear
        $this->postJson("/api/agentes/{$agentId}/disponibilidad", [
            'disponible' => false,
            'ttlMinutos' => 120,
        ]);

        $this->deleteRequest("/api/agentes/{$agentId}/disponibilidad");
        $this->assertResponseStatusCodeSame(204);
    }

    public function testClearAvailabilityIdempotent(): void
    {
        $agentId = $this->createAgent();

        // First call — no override exists, but should still succeed (idempotent)
        $this->deleteRequest("/api/agentes/{$agentId}/disponibilidad");
        $this->assertResponseStatusCodeSame(204);

        // Second call
        $this->deleteRequest("/api/agentes/{$agentId}/disponibilidad");
        $this->assertResponseStatusCodeSame(204);
    }

    // ────────────────────────────────────────────────────────
    //  GET /api/agentes/{id}/disponibilidad
    // ────────────────────────────────────────────────────────

    public function testGetAvailabilityWithoutOverride(): void
    {
        $agentId = $this->createAgent(timezone: 'America/Argentina/Buenos_Aires');

        $data = $this->getJson("/api/agentes/{$agentId}/disponibilidad");

        $this->assertResponseStatusCodeSame(200);

        // Forma: claves del contrato (anexo A.9)
        $this->assertSame(['agenteId', 'disponible', 'enTurno', 'zonaHoraria', 'cargaActual', 'capacidadMaxima', 'disponibilidadManual'], array_keys($data));
        $this->assertArrayHasKey('agenteId', $data);
        $this->assertArrayHasKey('disponible', $data);
        $this->assertArrayHasKey('enTurno', $data);
        $this->assertArrayHasKey('zonaHoraria', $data);
        $this->assertArrayHasKey('cargaActual', $data);
        $this->assertArrayHasKey('capacidadMaxima', $data);
        $this->assertArrayHasKey('disponibilidadManual', $data);

        // Verify types
        $this->assertSame($agentId, $data['agenteId']);
        $this->assertSame('America/Argentina/Buenos_Aires', $data['zonaHoraria']);
        $this->assertIsInt($data['cargaActual']);
        $this->assertIsInt($data['capacidadMaxima']);
        $this->assertIsBool($data['disponible']);
        $this->assertIsBool($data['enTurno']);

        // Override should be inactive
        $this->assertSame(['activa', 'disponible', 'vencimientoAt'], array_keys($data['disponibilidadManual']));
        $this->assertFalse($data['disponibilidadManual']['activa']);
        $this->assertNull($data['disponibilidadManual']['disponible']);
        $this->assertNull($data['disponibilidadManual']['vencimientoAt']);
    }

    public function testGetAvailabilityWithOverride(): void
    {
        $agentId = $this->createAgent(timezone: 'UTC');

        // Set an override (camelCase keys match DTO properties)
        $this->postJson("/api/agentes/{$agentId}/disponibilidad", [
            'disponible' => false,
            'ttlMinutos' => 120,
        ]);

        $data = $this->getJson("/api/agentes/{$agentId}/disponibilidad");

        $this->assertResponseStatusCodeSame(200);

        // Full shape
        $this->assertArrayHasKey('agenteId', $data);
        $this->assertArrayHasKey('disponible', $data);
        $this->assertArrayHasKey('enTurno', $data);
        $this->assertArrayHasKey('zonaHoraria', $data);
        $this->assertArrayHasKey('cargaActual', $data);
        $this->assertArrayHasKey('capacidadMaxima', $data);
        $this->assertArrayHasKey('disponibilidadManual', $data);

        // Override should be active
        $this->assertIsArray($data['disponibilidadManual']);
        $this->assertTrue($data['disponibilidadManual']['activa'], 'Override should be active after POST');
        $this->assertFalse($data['disponibilidadManual']['disponible']);
        $this->assertNotNull($data['disponibilidadManual']['vencimientoAt'], 'vencimientoAt should be set when ttlMinutos is provided');
    }

    public function testGetAvailabilityAgentNotFound(): void
    {
        $this->getJson('/api/agentes/99999/disponibilidad');

        $this->assertResponseStatusCodeSame(404);
    }

    // ────────────────────────────────────────────────────────
    //  POST /api/agentes/{id}/turnos
    // ────────────────────────────────────────────────────────

    public function testCreateShiftValid(): void
    {
        $agentId = $this->createAgent();

        $data = $this->postJson("/api/agentes/{$agentId}/turnos", [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(['turnoId', 'agenteId', 'diaDeLaSemana', 'horaDeInicio', 'horaDeFin'], array_keys($data));
        $this->assertSame($agentId, $data['agenteId']);
        $this->assertSame(1, $data['diaDeLaSemana']);
        $this->assertSame('08:00', $data['horaDeInicio']);
        $this->assertSame('16:00', $data['horaDeFin']);
    }

    public function testCreateShiftDuplicateReturns409(): void
    {
        $agentId = $this->createAgent();

        // First shift — should succeed
        $this->postJson("/api/agentes/{$agentId}/turnos", [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Duplicate shift — should return 409, NOT 500
        $this->postJson("/api/agentes/{$agentId}/turnos", [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateShiftDuplicateDifferentAgentSucceeds(): void
    {
        $agent1 = $this->createAgent('Agent 1');
        $agent2 = $this->createAgent('Agent 2');

        // Create shift for agent 1
        $this->postJson("/api/agentes/{$agent1}/turnos", [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Same shift for agent 2 — should succeed (different agent)
        $this->postJson("/api/agentes/{$agent2}/turnos", [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testCreateShiftInvalidDayOfWeek(): void
    {
        $agentId = $this->createAgent();

        // dayOfWeek = 0 is out of range
        $this->postJson("/api/agentes/{$agentId}/turnos", [
            'diaDeLaSemana' => 0,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['error' => 'diaDeLaSemana must be between 1 and 7'], json_decode($this->client->getResponse()->getContent() ?: '', true));
    }

    public function testCreateShiftEndTimeEqualsStartTime(): void
    {
        $agentId = $this->createAgent();

        $this->postJson("/api/agentes/{$agentId}/turnos", [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '08:00',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['error' => 'horaDeInicio and horaDeFin must differ'], json_decode($this->client->getResponse()->getContent() ?: '', true));
    }

    public function testCreateShiftInvalidAgent(): void
    {
        $this->postJson('/api/agentes/99999/turnos', [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    // ────────────────────────────────────────────────────────
    //  DELETE /api/agentes/{id}/turnos/{turnoId}
    // ────────────────────────────────────────────────────────

    public function testDeleteShiftExists(): void
    {
        $agentId = $this->createAgent();

        // Create a shift
        $created = $this->postJson("/api/agentes/{$agentId}/turnos", [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);
        $shiftId = $created['turnoId'];

        // Delete it
        $this->deleteRequest("/api/agentes/{$agentId}/turnos/{$shiftId}");
        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeleteShiftNonExistent(): void
    {
        $agentId = $this->createAgent();

        $this->deleteRequest("/api/agentes/{$agentId}/turnos/99999");
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteShiftWrongAgent(): void
    {
        $agent1 = $this->createAgent('Agent 1');
        $agent2 = $this->createAgent('Agent 2');

        // Create shift for agent 1
        $created = $this->postJson("/api/agentes/{$agent1}/turnos", [
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
        ]);
        $shiftId = $created['turnoId'];

        // Try to delete from agent 2
        $this->deleteRequest("/api/agentes/{$agent2}/turnos/{$shiftId}");
        // Shift doesn't belong to agent2 — returns 404
        $this->assertResponseStatusCodeSame(404);
    }
}
