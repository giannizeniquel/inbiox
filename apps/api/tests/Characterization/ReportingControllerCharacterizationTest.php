<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Agente\Agente;
use App\Disponibilidad\Turno;
use App\Reporte\Query\ReportingService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization of App\Reporte\Controller\ReportingController: every
 * JSON endpoint except export (covered by ReportsCsvExportTest), plus the
 * generic 500 envelope (shape S1, "no trace" variant).
 */
final class ReportingControllerCharacterizationTest extends CharacterizationTestCase
{
    private const WINDOW = 'from=2025-03-01T00:00:00Z&to=2025-03-03T00:00:00Z';

    private Agente $owner;

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

    public function testAgentPerformanceReportsAnAgentInShift(): void
    {
        $this->seedTickets();
        // A 00:00-00:00 shift is read as a cross-midnight shift that covers the
        // whole day, so one per weekday keeps the agent in shift at any time.
        foreach (range(1, 7) as $dayOfWeek) {
            $shift = new Turno($this->owner, $dayOfWeek, new \DateTimeImmutable('00:00:00'), new \DateTimeImmutable('00:00:00'));
            $this->owner->addShift($shift);
            $this->em->persist($shift);
        }
        $this->em->flush();
        $token = $this->supervisorToken();

        $body = $this->json($this->request('GET', '/api/reports/agents?' . self::WINDOW, $token));

        self::assertTrue($body[0]['is_in_shift']);
        self::assertSame(['current_load', 'is_in_shift', 'avg_frt_seconds'], \array_slice(\array_keys($body[0]), 6, 3));
    }

    public function testAgentPerformanceFilteredByUnknownAgentIsEmpty(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [], $this->request('GET', '/api/reports/agents?' . self::WINDOW . '&agent_id=999999999', $token));
    }

    // ── app_report_frt ──

    public function testFirstResponseTime(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            'avg_frt_seconds' => 1800,
            'min_frt_seconds' => 1800,
            'max_frt_seconds' => 1800,
            'avg_frt_minutes' => 30,
            'min_frt_minutes' => 30,
            'max_frt_minutes' => 30,
        ], $this->request('GET', '/api/reports/frt?' . self::WINDOW, $token));
    }

    // ── app_report_agent_load ──

    public function testAgentLoad(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [[
            'agent_id' => $this->owner->getId(),
            'agent_name' => 'Report Owner',
            'active_tickets' => 1,
            'max_capacity' => 10,
            'load_percent' => 10,
        ]], $this->request('GET', '/api/reports/agent-load', $token));
    }

    // ── app_report_unassigned ──

    public function testUnassignedListsTicketsButCountsZero(): void
    {
        // The count is always 0 today (group F / R8); pinned as current behavior.
        $this->seedTickets();
        $outside = $this->connection()->fetchOne("SELECT id FROM tickets WHERE status = 'new'");
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            'count' => 0,
            'tickets' => [[
                'id' => (int) $outside,
                'contact_name' => null,
                'created_at' => '2025-04-01 10:00:00',
                'priority' => 'medium',
            ]],
        ], $this->request('GET', '/api/reports/unassigned', $token));
        $this->assertJsonResponse(200, ['count' => 0, 'tickets' => []], $this->request('GET', '/api/reports/unassigned?' . self::WINDOW, $token));
    }

    public function testUnassignedRejectsAMalformedDate(): void
    {
        $token = $this->supervisorToken();

        $this->assertJsonResponse(400, ['error' => 'Invalid datetime format: 2025-03-01. Expected: Y-m-d\TH:i:sZ'], $this->request('GET', '/api/reports/unassigned?from=2025-03-01', $token));
    }

    // ── app_report_available_agents ──

    public function testAvailableAgentsForASupervisor(): void
    {
        $this->seedTickets();
        $this->owner->setIsAvailableOverride(true);
        $this->createAgent('Off Override')->setIsAvailableOverride(false);
        $this->em->flush();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [[
            'agent_id' => $this->owner->getId(),
            'agent_name' => 'Report Owner',
            'in_shift' => true,
            'active_tickets' => 1,
        ]], $this->request('GET', '/api/reports/available-agents', $token));
    }

    public function testAvailableAgentsAreFilteredByTheAgentsActiveWorkspace(): void
    {
        $this->seedTickets();
        $this->owner->setIsAvailableOverride(true);
        $workspace = $this->createWorkspace('char-rep-avail', 'Reports');
        $member = $this->createAgent('Workspace Member');
        $member->setIsAvailableOverride(true);
        $member->addWorkspace($workspace);
        $viewer = $this->createAgent('Viewer');
        $viewer->addWorkspace($workspace);
        $viewer->setActiveWorkspace($workspace);
        $this->em->flush();
        $token = $this->loginAs('reports-avail@characterization.local', ['ROLE_AGENT'], $viewer);

        $this->assertJsonResponse(200, [[
            'agent_id' => $member->getId(),
            'agent_name' => 'Workspace Member',
            'in_shift' => true,
            'active_tickets' => 0,
        ]], $this->request('GET', '/api/reports/available-agents', $token));
    }

    // ── app_report_frt_trend ──

    public function testFirstResponseTimeTrend(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            ['date' => '2025-03-01', 'avg_frt_seconds' => 1800, 'ticket_count' => 1],
        ], $this->request('GET', '/api/reports/frt-trend?' . self::WINDOW, $token));
    }

    // ── app_report_heatmap ──

    public function testHeatmap(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        // 2025-03-01 is a Saturday (ISO 6), 2025-03-02 a Sunday (ISO 7).
        $this->assertJsonResponse(200, [
            ['day_of_week' => 6, 'hour' => 10, 'count' => 1],
            ['day_of_week' => 7, 'hour' => 9, 'count' => 1],
            ['day_of_week' => 7, 'hour' => 15, 'count' => 1],
        ], $this->request('GET', '/api/reports/heatmap?' . self::WINDOW, $token));
    }

    // ── app_report_recurring ──

    public function testRecurringCustomersAndTheirThreshold(): void
    {
        $frequent = $this->createContact('+5491100000701', 'Frequent');
        $frequent->setEmail('frequent@example.com');
        $twice = $this->createContact('+5491100000702', 'Twice');
        $old = $this->createContact('+5491100000703', 'Old');
        $lastAt = [];
        foreach ([[$frequent, 1], [$frequent, 2], [$frequent, 3], [$twice, 4], [$twice, 5], [$old, 100], [$old, 101], [$old, 102]] as [$contact, $daysAgo]) {
            $ticket = $this->createTicket('new', null, $contact);
            $createdAt = (new \DateTimeImmutable('today'))->modify("-{$daysAgo} days")->format('Y-m-d H:i:s');
            $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', [$createdAt, $ticket->getId()]);
            $lastAt[$contact->getNombre()] ??= $createdAt;
        }
        $token = $this->supervisorToken();

        $frequentRow = [
            'contact_id' => $frequent->getId(),
            'contact_name' => 'Frequent',
            'email' => 'frequent@example.com',
            'ticket_count' => 3,
            'last_ticket_at' => $lastAt['Frequent'],
        ];
        $twiceRow = [
            'contact_id' => $twice->getId(),
            'contact_name' => 'Twice',
            'email' => null,
            'ticket_count' => 2,
            'last_ticket_at' => $lastAt['Twice'],
        ];

        $this->assertJsonResponse(200, [$frequentRow], $this->request('GET', '/api/reports/recurring-customers', $token));
        $this->assertJsonResponse(200, [$frequentRow, $twiceRow], $this->request('GET', '/api/reports/recurring-customers?threshold=2', $token));
        // threshold=0 is falsy and falls back to 3.
        $this->assertJsonResponse(200, [$frequentRow], $this->request('GET', '/api/reports/recurring-customers?threshold=0', $token));
    }

    // ── app_report_reopened ──

    public function testReopenedTickets(): void
    {
        $this->seedTickets();
        $resolvedId = (int) $this->connection()->fetchOne("SELECT id FROM tickets WHERE status = 'resolved'");
        foreach ([['auto_reopened', '2025-03-02 11:00:00'], ['auto_reopened', '2025-03-05 11:00:00'], ['status_changed', '2025-03-02 12:00:00']] as [$type, $at]) {
            $this->connection()->executeStatement(
                "INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, ?, 'system', ?)",
                [$resolvedId, $type, $at],
            );
        }
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [[
            'ticket_id' => $resolvedId,
            'contact_name' => null,
            'resolved_at' => '2025-03-01 12:00:00',
            'reopened_at' => '2025-03-02 11:00:00',
            'current_status' => 'resolved',
        ]], $this->request('GET', '/api/reports/reopened?' . self::WINDOW, $token));
    }

    // ── app_report_response_delay and app_report_ticket_participation ──

    /**
     * One ticket inside the window: a burst of two inbound messages answered
     * by the owner after 5 minutes, then an unanswered inbound message.
     */
    private function seedConversation(): int
    {
        $this->owner = $this->createAgent('Report Owner');
        $ticket = $this->createTicket('active', $this->owner->getId());
        $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', ['2025-03-01 10:00:00', $ticket->getId()]);
        $conversationId = $ticket->getConversacion()->getId();
        foreach ([
            ['inbound', 'contact', null, '2025-03-01 10:00:00'],
            ['inbound', 'contact', null, '2025-03-01 10:01:00'],
            ['outbound', 'agent', $this->owner->getId(), '2025-03-01 10:06:00'],
            ['inbound', 'contact', null, '2025-03-01 10:10:00'],
        ] as [$direction, $senderType, $agentId, $at]) {
            $this->connection()->executeStatement(
                "INSERT INTO messages (conversation_id, direction, content, created_at, sender_agent_id, sender_type) VALUES (?, ?, 'msg', ?, ?, ?)",
                [$conversationId, $direction, $at, $agentId, $senderType],
            );
        }

        return $ticket->getId();
    }

    public function testResponseDelay(): void
    {
        $this->seedConversation();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            'data_available_from' => '2025-03-01 10:00:00',
            'partial_data' => true,
            'aggregate' => ['avg_delay_seconds' => 360, 'sample_count' => 1, 'unanswered_inbound_count' => 1],
            'per_agent' => [[
                'agent_id' => $this->owner->getId(),
                'agent_name' => 'Report Owner',
                'avg_delay_seconds' => 360,
                'sample_count' => 1,
            ]],
        ], $this->request('GET', '/api/reports/response-delay?' . self::WINDOW, $token));
    }

    public function testTicketParticipation(): void
    {
        $ticketId = $this->seedConversation();
        $token = $this->supervisorToken();

        $ownerId = $this->owner->getId();

        $this->assertJsonResponse(200, [
            'ticket_id' => $ticketId,
            'data_available_from' => '2025-03-01 10:00:00',
            'partial_data' => false,
            'participants' => [[
                'agent_id' => $ownerId,
                'agent_name' => 'Report Owner',
                'message_count' => 1,
                'sample_count' => 1,
                'avg_delay_seconds' => 360,
            ]],
            'automatic_message_count' => 0,
            'unanswered_inbound_count' => 1,
            'pairs' => [
                ['run_start_at' => '2025-03-01 10:00:00', 'reply_at' => '2025-03-01 10:06:00', 'agent_id' => $ownerId, 'delay_seconds' => 360],
                ['run_start_at' => '2025-03-01 10:10:00', 'reply_at' => null, 'agent_id' => null, 'delay_seconds' => null],
            ],
        ], $this->request('GET', '/api/reports/tickets/' . $ticketId . '/participation', $token));
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
            'frt' => ['/api/reports/frt'],
            'frt-trend' => ['/api/reports/frt-trend'],
            'heatmap' => ['/api/reports/heatmap'],
            'reopened' => ['/api/reports/reopened'],
            'response-delay' => ['/api/reports/response-delay'],
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
