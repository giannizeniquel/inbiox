<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Agente\Agent;
use App\Reporte\Query\ReportingService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization of App\Reporte\Controller\ReportingController:
 * app_report_rt, app_report_counts, app_report_volume, app_report_agents,
 * plus the generic 500 envelope (shape S1, "no trace" variant).
 */
final class ReportingControllerCharacterizationTest extends CharacterizationTestCase
{
    private const WINDOW = 'from=2025-03-01T00:00:00Z&to=2025-03-03T00:00:00Z';

    private Agent $owner;

    /**
     * Three tickets inside the window, all owned by one agent, and one outside it.
     */
    private function seedTickets(): void
    {
        $this->owner = $this->createAgent('Report Owner');
        $ownerId = $this->owner->getId();

        $resolved = $this->createTicket('resolved', $ownerId);
        $closed = $this->createTicket('closed', $ownerId);
        $active = $this->createTicket('active', $ownerId);
        $outside = $this->createTicket('new');

        $set = function (int $id, string $createdAt, ?string $resolvedAt, ?string $firstResponseAt): void {
            $this->connection()->executeStatement(
                'UPDATE tickets SET created_at = ?, resolved_at = ?, first_response_at = ? WHERE id = ?',
                [$createdAt, $resolvedAt, $firstResponseAt, $id],
            );
        };
        $set($resolved->getId(), '2025-03-01 10:00:00', '2025-03-01 12:00:00', '2025-03-01 10:30:00');
        $set($closed->getId(), '2025-03-02 09:00:00', '2025-03-02 10:00:00', null);
        $set($active->getId(), '2025-03-02 15:00:00', null, null);
        $set($outside->getId(), '2025-04-01 10:00:00', null, null);
    }

    private function supervisorToken(): string
    {
        // A supervisor without an agent: no workspace scope, and no extra agent row.
        return $this->loginAs('reports@characterization.local', ['ROLE_SUPERVISOR']);
    }

    // ── app_report_rt ──

    public function testResolutionTime(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $response = $this->request('GET', '/api/reports/rt?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, [
            'avg_rt_seconds' => 5400,
            'min_rt_seconds' => 3600,
            'max_rt_seconds' => 7200,
            'avg_rt_minutes' => 90,
            'min_rt_minutes' => 60,
            'max_rt_minutes' => 120,
        ], $response);
    }

    public function testResolutionTimeWithNoData(): void
    {
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            'avg_rt_seconds' => null,
            'min_rt_seconds' => null,
            'max_rt_seconds' => null,
            'avg_rt_minutes' => null,
            'min_rt_minutes' => null,
            'max_rt_minutes' => null,
        ], $this->request('GET', '/api/reports/rt?' . self::WINDOW, $token));
    }

    // ── app_report_counts ──

    public function testCountsByStatus(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $response = $this->request('GET', '/api/reports/counts?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, ['new' => 0, 'active' => 1, 'waiting' => 0, 'resolved' => 1, 'closed' => 1], $response);
    }

    public function testCountsAreScopedToTheAgentsActiveWorkspace(): void
    {
        $this->seedTickets();
        $workspace = $this->createWorkspace('char-rep-ws', 'Reports');
        $this->connection()->executeStatement(
            "UPDATE tickets SET phone_number_id = 'char-rep-ws' WHERE status = 'active'",
        );
        $agent = $this->createAgent('Scoped');
        $agent->setActiveWorkspace($workspace);
        $token = $this->loginAs('reports-agent@characterization.local', ['ROLE_AGENT'], $agent);

        $response = $this->request('GET', '/api/reports/counts?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, ['new' => 0, 'active' => 1, 'waiting' => 0, 'resolved' => 0, 'closed' => 0], $response);
    }

    // ── app_report_volume ──

    public function testVolumePerDay(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $response = $this->request('GET', '/api/reports/volume?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, [
            ['date' => '2025-03-01', 'tickets_created' => 1, 'tickets_resolved' => 1],
            ['date' => '2025-03-02', 'tickets_created' => 2, 'tickets_resolved' => 1],
        ], $response);
    }

    // ── app_report_agents ──

    public function testAgentPerformance(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $response = $this->request('GET', '/api/reports/agents?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, [[
            'agent_id' => $this->owner->getId(),
            'agent_name' => 'Report Owner',
            'tickets_assigned' => 3,
            'tickets_resolved' => 2,
            'current_active_tickets' => 1,
            'max_active_tickets' => 10,
            'current_load' => 0.1,
            'is_in_shift' => false,
            'avg_frt_seconds' => 1800,
            'avg_frt_minutes' => 30,
            'avg_rt_seconds' => 5400,
            'avg_rt_minutes' => 90,
        ]], $response);
    }

    public function testAgentPerformanceFilteredByUnknownAgentIsEmpty(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [], $this->request('GET', '/api/reports/agents?' . self::WINDOW . '&agent_id=999999999', $token));
    }

    // ── shared parameter errors ──

    #[DataProvider('reportRoutes')]
    public function testDateParameterErrors(string $route): void
    {
        $token = $this->supervisorToken();

        $this->assertJsonResponse(400, ['error' => 'Missing required parameter: from or to'], $this->request('GET', $route . '?from=2025-03-01T00:00:00Z', $token));
        $this->assertJsonResponse(400, ['error' => 'Invalid datetime format: 2025-03-01. Expected: Y-m-d\TH:i:sZ'], $this->request('GET', $route . '?from=2025-03-01&to=2025-03-02T00:00:00Z', $token));
        $this->assertJsonResponse(400, ['error' => '"from" must be before "to"'], $this->request('GET', $route . '?from=2025-03-02T00:00:00Z&to=2025-03-01T00:00:00Z', $token));
    }

    public static function reportRoutes(): array
    {
        return [
            'rt' => ['/api/reports/rt'],
            'counts' => ['/api/reports/counts'],
            'volume' => ['/api/reports/volume'],
            'agents' => ['/api/reports/agents'],
        ];
    }

    public function testUnexpectedFailureReturnsGenericEnvelope(): void
    {
        $token = $this->supervisorToken();
        $service = $this->createMock(ReportingService::class);
        $service->method('getCounts')->willThrowException(new \RuntimeException('report exploded'));
        static::getContainer()->set(ReportingService::class, $service);

        $response = $this->request('GET', '/api/reports/counts?' . self::WINDOW, $token);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertStringNotContainsString('report exploded', (string) $response->getContent());
    }
}
