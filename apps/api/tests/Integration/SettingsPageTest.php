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
 * HTTP-level integration tests for the Settings Page endpoints.
 *
 * Covers MiConfiguracionController settings endpoints:
 *   GET  /api/mi/configuracion
 *   PUT  /api/mi/perfil
 *   PUT  /api/mi/notificaciones
 *   PUT  /api/mi/respuesta-automatica
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
        $user = new Usuario();
        $user->setEmail('settings-agent@inbiox.local');
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_AGENTE']);
        $this->em->persist($user);
        $this->em->flush();

        $agent = new Agente('Settings Agent');
        $agent->setUsuario($user);
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
    //  GET /api/mi/configuracion
    // ────────────────────────────────────────────────────────

    public function testGetSettingsReturnsAllSections(): void
    {
        $data = $this->getJson('/api/mi/configuracion');

        $this->assertResponseStatusCodeSame(200);

        // Profile section
        $this->assertArrayHasKey('perfil', $data);
        $this->assertArrayHasKey('nombre', $data['perfil']);
        $this->assertArrayHasKey('email', $data['perfil']);
        $this->assertArrayHasKey('firma', $data['perfil']);
        $this->assertSame('Settings Agent', $data['perfil']['nombre']);

        // Notifications section
        $this->assertArrayHasKey('notificaciones', $data);
        $this->assertArrayHasKey('sonido', $data['notificaciones']);
        $this->assertArrayHasKey('escritorio', $data['notificaciones']);
        $this->assertTrue($data['notificaciones']['sonido']);
        $this->assertTrue($data['notificaciones']['escritorio']);

        // AutoReply section
        $this->assertArrayHasKey('respuestaAutomatica', $data);
        $this->assertArrayHasKey('habilitada', $data['respuestaAutomatica']);
        $this->assertArrayHasKey('texto', $data['respuestaAutomatica']);
        $this->assertFalse($data['respuestaAutomatica']['habilitada']);
        $this->assertNull($data['respuestaAutomatica']['texto']);

        // Workspace section
        $this->assertArrayHasKey('espacioDeTrabajo', $data);
        $this->assertNull($data['espacioDeTrabajo']);

        // Business hours section
        $this->assertArrayHasKey('horarioDeAtencion', $data);
        $this->assertIsArray($data['horarioDeAtencion']);
        $this->assertEmpty($data['horarioDeAtencion']);
    }

    // ────────────────────────────────────────────────────────
    //  PUT /api/mi/perfil
    // ────────────────────────────────────────────────────────

    public function testUpdateProfileSignature(): void
    {
        $data = $this->putJson('/api/mi/perfil', [
            'firma' => 'Equipo de soporte',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('firma', $data);
        $this->assertSame('Equipo de soporte', $data['firma']);

        // Verify persistence
        $settings = $this->getJson('/api/mi/configuracion');
        $this->assertSame('Equipo de soporte', $settings['perfil']['firma']);
    }

    public function testUpdateProfileNullSignature(): void
    {
        // First set a signature
        $this->putJson('/api/mi/perfil', ['firma' => 'Test']);
        $this->assertResponseStatusCodeSame(200);

        // Then clear it
        $data = $this->putJson('/api/mi/perfil', ['firma' => null]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($data['firma']);
    }

    // ────────────────────────────────────────────────────────
    //  PUT /api/mi/notificaciones
    // ────────────────────────────────────────────────────────

    public function testUpdateNotificationsPartial(): void
    {
        // Toggle sound off — desktop should remain true
        $data = $this->putJson('/api/mi/notificaciones', [
            'sonido' => false,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['sonido']);
        $this->assertTrue($data['escritorio']); // unchanged
    }

    public function testUpdateNotificationsBoth(): void
    {
        $data = $this->putJson('/api/mi/notificaciones', [
            'sonido' => false,
            'escritorio' => false,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['sonido']);
        $this->assertFalse($data['escritorio']);
    }

    // ────────────────────────────────────────────────────────
    //  PUT /api/mi/respuesta-automatica
    // ────────────────────────────────────────────────────────

    public function testUpdateAutoReplyToggle(): void
    {
        $data = $this->putJson('/api/mi/respuesta-automatica', [
            'habilitada' => true,
            'texto' => 'Estamos fuera de horario, escribinos en nuestro horario laboral.',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($data['habilitada']);
        $this->assertSame('Estamos fuera de horario, escribinos en nuestro horario laboral.', $data['texto']);

        // Verify persistence
        $settings = $this->getJson('/api/mi/configuracion');
        $this->assertTrue($settings['respuestaAutomatica']['habilitada']);
        $this->assertSame('Estamos fuera de horario, escribinos en nuestro horario laboral.', $settings['respuestaAutomatica']['texto']);
    }

    public function testUpdateAutoReplyDisable(): void
    {
        // First enable
        $this->putJson('/api/mi/respuesta-automatica', ['habilitada' => true, 'texto' => 'Test']);
        $this->assertResponseStatusCodeSame(200);

        // Then disable
        $data = $this->putJson('/api/mi/respuesta-automatica', [
            'habilitada' => false,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['habilitada']);
        $this->assertNull($data['texto']);
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
        $this->client->request('GET', '/api/mi/configuracion', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    // ────────────────────────────────────────────────────────
    //  Private helpers
    // ────────────────────────────────────────────────────────

    private function loadAgent(): Agente
    {
        $user = $this->em->getRepository(Usuario::class)->findOneBy(['email' => 'settings-agent@inbiox.local']);
        \assert($user !== null);
        $agent = $user->getAgente();
        \assert($agent !== null);
        return $agent;
    }

    private function createShift(Agente $agent, int $dayOfWeek, string $start, string $end): Turno
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
