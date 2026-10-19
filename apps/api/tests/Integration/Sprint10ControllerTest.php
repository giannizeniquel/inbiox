<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agente\Agent;
use App\Disponibilidad\Turno;
use App\Administracion\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level integration tests for Sprint 10 Agent Management endpoints.
 *
 * Covers DisponibilidadDeAgenteController's 5 endpoints:
 *   POST   /agents/{id}/availability
 *   DELETE /agents/{id}/availability
 *   GET    /agents/{id}/availability
 *   POST   /agents/{id}/shifts
 *   DELETE /agents/{id}/shifts/{shiftId}
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
        $user = new User();
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
        $agent = new Agent($name);
        $agent->setTimezone($timezone);
        $agent->setMaxActiveTickets($maxActiveTickets);
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
    //  POST /agents/{id}/availability
    // ────────────────────────────────────────────────────────

    public function testSetAvailabilityValid(): void
    {
        $agentId = $this->createAgent();

        $data = $this->postJson("/api/agents/{$agentId}/availability", [
            'isAvailable' => false,
            'ttlMinutes' => 120,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('is_available', $data);
        $this->assertFalse($data['is_available']);
    }

    public function testSetAvailabilityInvalidAgent(): void
    {
        $this->postJson('/api/agents/99999/availability', [
            'isAvailable' => false,
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertStringContainsString('not found', $this->client->getResponse()->getContent() ?: '');
    }

    public function testSetAvailabilityMissingFields(): void
    {
        $agentId = $this->createAgent();

        $this->postJson("/api/agents/{$agentId}/availability", []);

        // Missing required 'isAvailable' should be caught by MapRequestPayload validation
        $this->assertResponseStatusCodeSame(422);
    }

    // ────────────────────────────────────────────────────────
    //  DELETE /agents/{id}/availability
    // ────────────────────────────────────────────────────────

    public function testClearAvailabilityExists(): void
    {
        $agentId = $this->createAgent();

        // First set an override so there's something to clear
        $this->postJson("/api/agents/{$agentId}/availability", [
            'isAvailable' => false,
            'ttlMinutes' => 120,
        ]);

        $this->deleteRequest("/api/agents/{$agentId}/availability");
        $this->assertResponseStatusCodeSame(204);
    }

    public function testClearAvailabilityIdempotent(): void
    {
        $agentId = $this->createAgent();

        // First call — no override exists, but should still succeed (idempotent)
        $this->deleteRequest("/api/agents/{$agentId}/availability");
        $this->assertResponseStatusCodeSame(204);

        // Second call
        $this->deleteRequest("/api/agents/{$agentId}/availability");
        $this->assertResponseStatusCodeSame(204);
    }

    // ────────────────────────────────────────────────────────
    //  GET /agents/{id}/availability
    // ────────────────────────────────────────────────────────

    public function testGetAvailabilityWithoutOverride(): void
    {
        $agentId = $this->createAgent(timezone: 'America/Argentina/Buenos_Aires');

        $data = $this->getJson("/api/agents/{$agentId}/availability");

        $this->assertResponseStatusCodeSame(200);

        // Shape: top-level snake_case keys
        $this->assertArrayHasKey('agent_id', $data);
        $this->assertArrayHasKey('is_available', $data);
        $this->assertArrayHasKey('is_in_shift', $data);
        $this->assertArrayHasKey('timezone', $data);
        $this->assertArrayHasKey('current_load', $data);
        $this->assertArrayHasKey('max_capacity', $data);
        $this->assertArrayHasKey('override', $data);

        // Verify types
        $this->assertSame($agentId, $data['agent_id']);
        $this->assertSame('America/Argentina/Buenos_Aires', $data['timezone']);
        $this->assertIsInt($data['current_load']);
        $this->assertIsInt($data['max_capacity']);
        $this->assertIsBool($data['is_available']);
        $this->assertIsBool($data['is_in_shift']);

        // Override should be inactive
        $this->assertIsArray($data['override']);
        $this->assertFalse($data['override']['active']);
        $this->assertNull($data['override']['is_available']);
        $this->assertNull($data['override']['expires_at']);
    }

    public function testGetAvailabilityWithOverride(): void
    {
        $agentId = $this->createAgent(timezone: 'UTC');

        // Set an override (camelCase keys match DTO properties)
        $this->postJson("/api/agents/{$agentId}/availability", [
            'isAvailable' => false,
            'ttlMinutes' => 120,
        ]);

        $data = $this->getJson("/api/agents/{$agentId}/availability");

        $this->assertResponseStatusCodeSame(200);

        // Full shape
        $this->assertArrayHasKey('agent_id', $data);
        $this->assertArrayHasKey('is_available', $data);
        $this->assertArrayHasKey('is_in_shift', $data);
        $this->assertArrayHasKey('timezone', $data);
        $this->assertArrayHasKey('current_load', $data);
        $this->assertArrayHasKey('max_capacity', $data);
        $this->assertArrayHasKey('override', $data);

        // Override should be active
        $this->assertIsArray($data['override']);
        $this->assertTrue($data['override']['active'], 'Override should be active after POST');
        $this->assertFalse($data['override']['is_available']);
        $this->assertNotNull($data['override']['expires_at'], 'expires_at should be set when ttl_minutes provided');
    }

    public function testGetAvailabilityAgentNotFound(): void
    {
        $this->getJson('/api/agents/99999/availability');

        $this->assertResponseStatusCodeSame(404);
    }

    // ────────────────────────────────────────────────────────
    //  POST /agents/{id}/shifts
    // ────────────────────────────────────────────────────────

    public function testCreateShiftValid(): void
    {
        $agentId = $this->createAgent();

        $data = $this->postJson("/api/agents/{$agentId}/shifts", [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('shift_id', $data);
        $this->assertArrayHasKey('day_of_week', $data);
        $this->assertSame(1, $data['day_of_week']);
        $this->assertSame('08:00', $data['start_time']);
        $this->assertSame('16:00', $data['end_time']);
    }

    public function testCreateShiftDuplicateReturns409(): void
    {
        $agentId = $this->createAgent();

        // First shift — should succeed
        $this->postJson("/api/agents/{$agentId}/shifts", [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Duplicate shift — should return 409, NOT 500
        $this->postJson("/api/agents/{$agentId}/shifts", [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateShiftDuplicateDifferentAgentSucceeds(): void
    {
        $agent1 = $this->createAgent('Agent 1');
        $agent2 = $this->createAgent('Agent 2');

        // Create shift for agent 1
        $this->postJson("/api/agents/{$agent1}/shifts", [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Same shift for agent 2 — should succeed (different agent)
        $this->postJson("/api/agents/{$agent2}/shifts", [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testCreateShiftInvalidDayOfWeek(): void
    {
        $agentId = $this->createAgent();

        // dayOfWeek = 0 is out of range
        $this->postJson("/api/agents/{$agentId}/shifts", [
            'dayOfWeek' => 0,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreateShiftEndTimeEqualsStartTime(): void
    {
        $agentId = $this->createAgent();

        $this->postJson("/api/agents/{$agentId}/shifts", [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '08:00',
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreateShiftInvalidAgent(): void
    {
        $this->postJson('/api/agents/99999/shifts', [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    // ────────────────────────────────────────────────────────
    //  DELETE /agents/{id}/shifts/{shiftId}
    // ────────────────────────────────────────────────────────

    public function testDeleteShiftExists(): void
    {
        $agentId = $this->createAgent();

        // Create a shift
        $created = $this->postJson("/api/agents/{$agentId}/shifts", [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);
        $shiftId = $created['shift_id'];

        // Delete it
        $this->deleteRequest("/api/agents/{$agentId}/shifts/{$shiftId}");
        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeleteShiftNonExistent(): void
    {
        $agentId = $this->createAgent();

        $this->deleteRequest("/api/agents/{$agentId}/shifts/99999");
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteShiftWrongAgent(): void
    {
        $agent1 = $this->createAgent('Agent 1');
        $agent2 = $this->createAgent('Agent 2');

        // Create shift for agent 1
        $created = $this->postJson("/api/agents/{$agent1}/shifts", [
            'dayOfWeek' => 1,
            'startTime' => '08:00',
            'endTime' => '16:00',
        ]);
        $shiftId = $created['shift_id'];

        // Try to delete from agent 2
        $this->deleteRequest("/api/agents/{$agent2}/shifts/{$shiftId}");
        // Shift doesn't belong to agent2 — returns 404
        $this->assertResponseStatusCodeSame(404);
    }
}
