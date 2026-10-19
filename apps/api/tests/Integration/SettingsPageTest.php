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
 * HTTP-level integration tests for the Settings Page endpoints.
 *
 * Covers MeController settings endpoints:
 *   GET  /api/me/settings
 *   PUT  /api/me/profile
 *   PUT  /api/me/notifications
 *   PUT  /api/me/auto-reply
 *   GET  /api/me/shifts
 *   PUT  /api/me/shifts
 */
class SettingsPageTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;
    private string $authToken = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Clean relevant tables
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

        // Create agent user and authenticate
        $this->authToken = $this->createAuthenticatedAgent();
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

    private function createAuthenticatedAgent(): string
    {
        $user = new User();
        $user->setEmail('settings-agent@inbiox.local');
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_AGENT']);
        $this->em->persist($user);
        $this->em->flush();

        $agent = new Agent('Settings Agent');
        $agent->setUser($user);
        $this->em->persist($agent);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'settings-agent@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        return $data['access_token'];
    }

    private function requestJson(string $method, string $uri, ?array $data = null): array
    {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ];

        if ($data !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $this->client->request(
            $method,
            $uri,
            [],
            [],
            $server,
            $data !== null ? \json_encode($data, JSON_THROW_ON_ERROR) : null,
        );

        $content = $this->client->getResponse()->getContent();

        return $content !== false && $content !== '' ? (array) \json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function getJson(string $uri): array
    {
        return $this->requestJson('GET', $uri);
    }

    private function putJson(string $uri, array $data): array
    {
        return $this->requestJson('PUT', $uri, $data);
    }

    // ────────────────────────────────────────────────────────
    //  GET /api/me/settings
    // ────────────────────────────────────────────────────────

    public function testGetSettingsReturnsAllSections(): void
    {
        $data = $this->getJson('/api/me/settings');

        $this->assertResponseStatusCodeSame(200);

        // Profile section
        $this->assertArrayHasKey('profile', $data);
        $this->assertArrayHasKey('name', $data['profile']);
        $this->assertArrayHasKey('email', $data['profile']);
        $this->assertArrayHasKey('signature', $data['profile']);
        $this->assertSame('Settings Agent', $data['profile']['name']);

        // Notifications section
        $this->assertArrayHasKey('notifications', $data);
        $this->assertArrayHasKey('sound', $data['notifications']);
        $this->assertArrayHasKey('desktop', $data['notifications']);
        $this->assertTrue($data['notifications']['sound']);
        $this->assertTrue($data['notifications']['desktop']);

        // AutoReply section
        $this->assertArrayHasKey('autoReply', $data);
        $this->assertArrayHasKey('enabled', $data['autoReply']);
        $this->assertArrayHasKey('message', $data['autoReply']);
        $this->assertFalse($data['autoReply']['enabled']);
        $this->assertNull($data['autoReply']['message']);

        // Workspace section
        $this->assertArrayHasKey('workspace', $data);
        $this->assertNull($data['workspace']);

        // Business hours section
        $this->assertArrayHasKey('businessHours', $data);
        $this->assertIsArray($data['businessHours']);
        $this->assertEmpty($data['businessHours']);
    }

    // ────────────────────────────────────────────────────────
    //  PUT /api/me/profile
    // ────────────────────────────────────────────────────────

    public function testUpdateProfileSignature(): void
    {
        $data = $this->putJson('/api/me/profile', [
            'signature' => 'Equipo de soporte',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('signature', $data);
        $this->assertSame('Equipo de soporte', $data['signature']);

        // Verify persistence
        $settings = $this->getJson('/api/me/settings');
        $this->assertSame('Equipo de soporte', $settings['profile']['signature']);
    }

    public function testUpdateProfileNullSignature(): void
    {
        // First set a signature
        $this->putJson('/api/me/profile', ['signature' => 'Test']);
        $this->assertResponseStatusCodeSame(200);

        // Then clear it
        $data = $this->putJson('/api/me/profile', ['signature' => null]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($data['signature']);
    }

    // ────────────────────────────────────────────────────────
    //  PUT /api/me/notifications
    // ────────────────────────────────────────────────────────

    public function testUpdateNotificationsPartial(): void
    {
        // Toggle sound off — desktop should remain true
        $data = $this->putJson('/api/me/notifications', [
            'sound' => false,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['sound']);
        $this->assertTrue($data['desktop']); // unchanged
    }

    public function testUpdateNotificationsBoth(): void
    {
        $data = $this->putJson('/api/me/notifications', [
            'sound' => false,
            'desktop' => false,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['sound']);
        $this->assertFalse($data['desktop']);
    }

    // ────────────────────────────────────────────────────────
    //  PUT /api/me/auto-reply
    // ────────────────────────────────────────────────────────

    public function testUpdateAutoReplyToggle(): void
    {
        $data = $this->putJson('/api/me/auto-reply', [
            'enabled' => true,
            'message' => 'Estamos fuera de horario, escribinos en nuestro horario laboral.',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($data['enabled']);
        $this->assertSame('Estamos fuera de horario, escribinos en nuestro horario laboral.', $data['message']);

        // Verify persistence
        $settings = $this->getJson('/api/me/settings');
        $this->assertTrue($settings['autoReply']['enabled']);
        $this->assertSame('Estamos fuera de horario, escribinos en nuestro horario laboral.', $settings['autoReply']['message']);
    }

    public function testUpdateAutoReplyDisable(): void
    {
        // First enable
        $this->putJson('/api/me/auto-reply', ['enabled' => true, 'message' => 'Test']);
        $this->assertResponseStatusCodeSame(200);

        // Then disable
        $data = $this->putJson('/api/me/auto-reply', [
            'enabled' => false,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['enabled']);
        $this->assertNull($data['message']);
    }

    // ────────────────────────────────────────────────────────
    //  GET /api/me/shifts
    // ────────────────────────────────────────────────────────

    public function testGetShiftsReturnsOrderedList(): void
    {
        $agent = $this->loadAgent();
        $this->createShift($agent, 2, '10:00', '18:00'); // Tuesday
        $this->createShift($agent, 1, '08:00', '16:00'); // Monday

        $data = $this->getJson('/api/me/shifts');

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(2, $data);

        // Should be sorted: Monday first, then Tuesday
        $this->assertSame(1, $data[0]['dayOfWeek']);
        $this->assertSame(2, $data[1]['dayOfWeek']);
    }

    public function testGetShiftsEmptyWhenNoShifts(): void
    {
        $data = $this->getJson('/api/me/shifts');
        $this->assertResponseStatusCodeSame(200);
        $this->assertEmpty($data);
    }

    // ────────────────────────────────────────────────────────
    //  PUT /api/me/shifts (replace-all)
    // ────────────────────────────────────────────────────────

    public function testReplaceShiftsReplacesAll(): void
    {
        $agent = $this->loadAgent();

        // Seed 2 shifts
        $this->createShift($agent, 1, '08:00', '16:00');
        $this->createShift($agent, 2, '09:00', '17:00');

        // Replace with 1 shift
        $data = $this->putJson('/api/me/shifts', [
            'shifts' => [
                ['dayOfWeek' => 3, 'start' => '10:00', 'end' => '18:00'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(1, $data);
        $this->assertSame(3, $data[0]['dayOfWeek']);

        // Verify only 1 shift remains
        $shifts = $this->getJson('/api/me/shifts');
        $this->assertCount(1, $shifts);
    }

    public function testReplaceShiftsEmptyRemovesAll(): void
    {
        $agent = $this->loadAgent();
        $this->createShift($agent, 1, '08:00', '16:00');

        $data = $this->putJson('/api/me/shifts', ['shifts' => []]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEmpty($data);

        $shifts = $this->getJson('/api/me/shifts');
        $this->assertEmpty($shifts);
    }

    // ────────────────────────────────────────────────────────
    //  Auth — 401
    // ────────────────────────────────────────────────────────

    public function testUnauthenticatedReturns401(): void
    {
        $this->client->request('GET', '/api/me/settings', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    // ────────────────────────────────────────────────────────
    //  Private helpers
    // ────────────────────────────────────────────────────────

    private function loadAgent(): Agent
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'settings-agent@inbiox.local']);
        \assert($user !== null);
        $agent = $user->getAgent();
        \assert($agent !== null);
        return $agent;
    }

    private function createShift(Agent $agent, int $dayOfWeek, string $start, string $end): Turno
    {
        $startTime = \DateTimeImmutable::createFromFormat('H:i', $start);
        $endTime = \DateTimeImmutable::createFromFormat('H:i', $end);
        \assert($startTime !== false && $endTime !== false);

        $shift = new Turno($agent, $dayOfWeek, $startTime, $endTime);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }
}
