<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use Symfony\Component\HttpFoundation\Response;

/**
 * Independent verifier probes for phase 4c (reporting). Each probe pins an
 * exact JSON body that must be identical before and after the refactor:
 * the shared ticket filter (agent, workspace, team) on every native query,
 * the workspace scope through an agent's active workspace, the `LIMIT`
 * caps of unassigned (50) and reopened (100), and participation 404.
 *
 * Database ids differ between runs, so bodies are compared after replacing
 * every known id under an id key with a stable token.
 */
final class ReportingVerifierProbeTest extends CharacterizationTestCase
{
    private const WINDOW = 'from=2025-03-01T00:00:00Z&to=2025-03-03T00:00:00Z';
    private const ID_KEYS = ['id', 'agent_id', 'ticket_id', 'contact_id'];

    /** @var array<int, string> */
    private array $tokens = [];

    private int $team7 = 0;
    private int $team8 = 0;

    private function token(int $id, string $name): void
    {
        $this->tokens[$id] = $name;
    }

    private function tokenize(mixed $value, ?string $key = null): mixed
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->tokenize($v, \is_string($k) ? $k : $key);
            }

            return $out;
        }
        if (\is_int($value) && null !== $key && \in_array($key, self::ID_KEYS, true)) {
            self::assertArrayHasKey($value, $this->tokens, "unexpected id {$value} under key {$key}");

            return $this->tokens[$value];
        }

        return $value;
    }

    private function assertBody(int $status, mixed $expected, Response $response): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($expected, $this->tokenize($this->json($response)));
    }

    /**
     * Five tickets in the window spread over two agents, two workspaces and
     * two teams, plus an unassigned one. Returns the scoped agent token.
     */
    private function seedFilterMatrix(): string
    {
        $workspace = $this->createWorkspace('probe-ws', 'Probe');
        $alpha = $this->createAgent('Alpha');
        $beta = $this->createAgent('Beta');
        $this->token($alpha->getId(), 'alpha');
        $this->token($beta->getId(), 'beta');
        $teamA = new \App\Agente\Equipo($workspace, 'Team A');
        $teamB = new \App\Agente\Equipo($workspace, 'Team B');
        $this->em->persist($teamA);
        $this->em->persist($teamB);
        $this->em->flush();
        $this->team7 = (int) $teamA->getId();
        $this->team8 = (int) $teamB->getId();
        $teams = [7 => $this->team7, 8 => $this->team8];

        $rows = [
            // name, agent, status, phone, team, created, first response, resolved
            ['t1', $alpha, 'resolved', 'probe-ws', 7, '2025-03-01 10:00:00', '2025-03-01 10:10:00', '2025-03-01 10:50:00'],
            ['t2', $alpha, 'active', 'probe-ws', 8, '2025-03-01 12:00:00', '2025-03-01 12:20:00', null],
            ['t3', $beta, 'resolved', 'probe-ws', 7, '2025-03-02 09:00:00', '2025-03-02 09:30:00', '2025-03-02 10:10:00'],
            ['t4', $alpha, 'waiting', 'other-ws', 7, '2025-03-02 10:00:00', '2025-03-02 10:40:00', null],
            ['t5', $beta, 'new', null, null, '2025-03-02 11:00:00', null, null],
            ['t6', null, 'new', 'probe-ws', 7, '2025-03-02 13:00:00', null, null],
        ];
        foreach ($rows as [$name, $agent, $status, $phone, $team, $created, $frt, $resolved]) {
            $ticket = $this->createTicket($status, $agent?->getId(), $this->createContact('+54911000' . \random_int(10000, 99999), 'C-' . $name));
            $this->token($ticket->getId(), $name);
            $this->token($ticket->getContacto()->getId(), 'contact-' . $name);
            $this->connection()->executeStatement(
                'UPDATE tickets SET phone_number_id = ?, team_id = ?, created_at = ?, first_response_at = ?, resolved_at = ? WHERE id = ?',
                [$phone, null === $team ? null : $teams[$team], $created, $frt, $resolved, $ticket->getId()],
            );
        }

        $viewer = $this->createAgent('Viewer');
        $this->token($viewer->getId(), 'viewer');
        $viewer->addEspacioDeTrabajo($workspace);
        $viewer->setEspacioDeTrabajoActivo($workspace);
        $this->em->flush();

        return $this->loginAs('probe-agent@characterization.local', ['ROLE_AGENT'], $viewer);
    }

    private function agentRow(string $agent, string $name, int $assigned, int $resolved, int $active, float|int $load, mixed $frt, mixed $rt): array
    {
        return [
            'agent_id' => $agent,
            'agent_name' => $name,
            'tickets_assigned' => $assigned,
            'tickets_resolved' => $resolved,
            'current_active_tickets' => $active,
            'max_active_tickets' => 10,
            'current_load' => $load,
            'is_in_shift' => false,
            'avg_frt_seconds' => $frt,
            'avg_frt_minutes' => $this->minutes($frt),
            'avg_rt_seconds' => $rt,
            'avg_rt_minutes' => $this->minutes($rt),
        ];
    }

    /**
     * JSON drops the fraction of whole floats, so they decode as integers.
     */
    private function minutes(?int $seconds): int|float|null
    {
        if (null === $seconds) {
            return null;
        }
        $minutes = \round($seconds / 60, 2);

        return $minutes == (int) $minutes ? (int) $minutes : $minutes;
    }

    public function testFiltersOnTheTimeMetricsForASupervisor(): void
    {
        $this->seedFilterMatrix();
        $token = $this->loginAs('probe-sup@characterization.local', ['ROLE_SUPERVISOR']);
        $alphaId = \array_search('alpha', $this->tokens, true);

        // agent + team
        $this->assertBody(200, $this->expectedFrt(), $this->request('GET', '/api/reports/frt?' . self::WINDOW . '&agent_id=' . $alphaId . '&teamId=' . $this->team7, $token));
        // team only
        $this->assertBody(200, [
            'avg_rt_seconds' => 3600, 'min_rt_seconds' => 3000, 'max_rt_seconds' => 4200,
            'avg_rt_minutes' => 60, 'min_rt_minutes' => 50, 'max_rt_minutes' => 70,
        ], $this->request('GET', '/api/reports/rt?' . self::WINDOW . '&teamId=' . $this->team7, $token));
        $this->assertBody(200, ['new' => 1, 'active' => 0, 'waiting' => 1, 'resolved' => 2, 'closed' => 0], $this->request('GET', '/api/reports/counts?' . self::WINDOW . '&teamId=' . $this->team7, $token));
        $this->assertBody(200, [
            ['date' => '2025-03-01', 'tickets_created' => 1, 'tickets_resolved' => 1],
            ['date' => '2025-03-02', 'tickets_created' => 3, 'tickets_resolved' => 1],
        ], $this->request('GET', '/api/reports/volume?' . self::WINDOW . '&teamId=' . $this->team7, $token));
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 2, 1, 1, 0.1, 1500, 3000),
            $this->agentRow('beta', 'Beta', 1, 1, 0, 0, 1800, 4200),
            $this->agentRow('viewer', 'Viewer', 0, 0, 0, 0, null, null),
        ], $this->request('GET', '/api/reports/agents?' . self::WINDOW . '&teamId=' . $this->team7, $token));
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 3, 1, 2, 0.2, 1400, 3000),
        ], $this->request('GET', '/api/reports/agents?' . self::WINDOW . '&agent_id=' . $alphaId, $token));
        // No filter at all.
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 3, 1, 2, 0.2, 1400, 3000),
            $this->agentRow('beta', 'Beta', 2, 1, 1, 0.1, 1800, 4200),
            $this->agentRow('viewer', 'Viewer', 0, 0, 0, 0, null, null),
        ], $this->request('GET', '/api/reports/agents?' . self::WINDOW, $token));
    }

    private function expectedFrt(): array
    {
        // alpha + team 7 in the window: t1 (600 s) and t4 (2400 s).
        return [
            'avg_frt_seconds' => 1500, 'min_frt_seconds' => 600, 'max_frt_seconds' => 2400,
            'avg_frt_minutes' => 25, 'min_frt_minutes' => 10, 'max_frt_minutes' => 40,
        ];
    }

    public function testEveryReportIsScopedToTheAgentsActiveWorkspace(): void
    {
        $token = $this->seedFilterMatrix();
        $alphaId = \array_search('alpha', $this->tokens, true);

        $this->assertBody(200, [
            'avg_frt_seconds' => 1200, 'min_frt_seconds' => 600, 'max_frt_seconds' => 1800,
            'avg_frt_minutes' => 20, 'min_frt_minutes' => 10, 'max_frt_minutes' => 30,
        ], $this->request('GET', '/api/reports/frt?' . self::WINDOW, $token));
        // workspace + agent + team on frt
        $this->assertBody(200, [
            'avg_frt_seconds' => 600, 'min_frt_seconds' => 600, 'max_frt_seconds' => 600,
            'avg_frt_minutes' => 10, 'min_frt_minutes' => 10, 'max_frt_minutes' => 10,
        ], $this->request('GET', '/api/reports/frt?' . self::WINDOW . '&agent_id=' . $alphaId . '&teamId=' . $this->team7, $token));
        $this->assertBody(200, [
            'avg_rt_seconds' => 3600, 'min_rt_seconds' => 3000, 'max_rt_seconds' => 4200,
            'avg_rt_minutes' => 60, 'min_rt_minutes' => 50, 'max_rt_minutes' => 70,
        ], $this->request('GET', '/api/reports/rt?' . self::WINDOW, $token));
        $this->assertBody(200, ['new' => 1, 'active' => 1, 'waiting' => 0, 'resolved' => 2, 'closed' => 0], $this->request('GET', '/api/reports/counts?' . self::WINDOW, $token));
        $this->assertBody(200, [
            ['date' => '2025-03-01', 'tickets_created' => 2, 'tickets_resolved' => 1],
            ['date' => '2025-03-02', 'tickets_created' => 2, 'tickets_resolved' => 1],
        ], $this->request('GET', '/api/reports/volume?' . self::WINDOW, $token));
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 2, 1, 1, 0.1, 900, 3000),
            $this->agentRow('beta', 'Beta', 1, 1, 0, 0, 1800, 4200),
            $this->agentRow('viewer', 'Viewer', 0, 0, 0, 0, null, null),
        ], $this->request('GET', '/api/reports/agents?' . self::WINDOW, $token));
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 1, 1, 0, 0, 600, 3000),
        ], $this->request('GET', '/api/reports/agents?' . self::WINDOW . '&agent_id=' . $alphaId . '&teamId=' . $this->team7, $token));
        $this->assertBody(200, [
            ['agent_id' => 'alpha', 'agent_name' => 'Alpha', 'active_tickets' => 1, 'max_capacity' => 10, 'load_percent' => 10],
            ['agent_id' => 'beta', 'agent_name' => 'Beta', 'active_tickets' => 0, 'max_capacity' => 10, 'load_percent' => 0],
            ['agent_id' => 'viewer', 'agent_name' => 'Viewer', 'active_tickets' => 0, 'max_capacity' => 10, 'load_percent' => 0],
        ], $this->request('GET', '/api/reports/agent-load', $token));
        $this->assertBody(200, [
            ['agent_id' => 'alpha', 'agent_name' => 'Alpha', 'active_tickets' => 1, 'max_capacity' => 10, 'load_percent' => 10],
        ], $this->request('GET', '/api/reports/agent-load?agent_id=' . $alphaId, $token));
        $unassignedRow = ['id' => 't6', 'contact_name' => 'C-t6', 'created_at' => '2025-03-02 13:00:00', 'priority' => 'medium'];
        $this->assertBody(200, ['count' => 0, 'tickets' => [$unassignedRow]], $this->request('GET', '/api/reports/unassigned', $token));
        $this->assertBody(200, ['count' => 0, 'tickets' => [$unassignedRow]], $this->request('GET', '/api/reports/unassigned?' . self::WINDOW, $token));
        $this->assertBody(200, ['count' => 0, 'tickets' => []], $this->request('GET', '/api/reports/unassigned?from=2025-03-02T14:00:00Z', $token));
        $this->assertBody(200, [
            ['date' => '2025-03-01', 'avg_frt_seconds' => 900, 'ticket_count' => 2],
            ['date' => '2025-03-02', 'avg_frt_seconds' => 1800, 'ticket_count' => 1],
        ], $this->request('GET', '/api/reports/frt-trend?' . self::WINDOW, $token));
        // 2025-03-01 is a Saturday (ISO 6), 2025-03-02 a Sunday (ISO 7).
        $this->assertBody(200, [
            ['day_of_week' => 6, 'hour' => 10, 'count' => 1],
            ['day_of_week' => 6, 'hour' => 12, 'count' => 1],
            ['day_of_week' => 7, 'hour' => 9, 'count' => 1],
            ['day_of_week' => 7, 'hour' => 13, 'count' => 1],
        ], $this->request('GET', '/api/reports/heatmap?' . self::WINDOW, $token));
    }

    public function testRecurringCustomersReopenedAndResponseDelayAreScoped(): void
    {
        $this->createWorkspace('probe-ws', 'Probe');
        $repeat = $this->createContact('+5491100000801', 'Repeat');
        $this->token($repeat->getId(), 'repeat');
        $owner = $this->createAgent('Owner');
        $this->token($owner->getId(), 'owner');
        $recent = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d H:i:s');
        $ids = [];
        foreach (['probe-ws', 'probe-ws', 'other-ws', 'other-ws'] as $i => $phone) {
            $ticket = $this->createTicket('resolved', $owner->getId(), $repeat);
            $ids[$i] = $ticket->getId();
            $this->token($ticket->getId(), 'r' . $i);
            $this->connection()->executeStatement(
                'UPDATE tickets SET phone_number_id = ?, created_at = ?, resolved_at = ? WHERE id = ?',
                [$phone, 0 === $i ? '2025-03-01 10:00:00' : $recent, '2025-03-01 12:00:00', $ticket->getId()],
            );
        }
        foreach ([0, 2] as $i) {
            $this->connection()->executeStatement(
                "INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, 'auto_reopened', 'system', '2025-03-02 11:00:00')",
                [$ids[$i]],
            );
            $conversationId = (int) $this->connection()->fetchOne('SELECT conversation_id FROM tickets WHERE id = ?', [$ids[$i]]);
            foreach ([['entrante', 'contacto', null, '2025-03-01 10:00:00'], ['saliente', 'agente', $owner->getId(), '2025-03-01 10:02:00']] as [$direction, $sender, $agentId, $at]) {
                $this->connection()->executeStatement(
                    "INSERT INTO messages (conversation_id, direction, content, created_at, sender_agent_id, sender_type) VALUES (?, ?, 'm', ?, ?, ?)",
                    [$conversationId, $direction, $at, $agentId, $sender],
                );
            }
        }
        // Ticket r2 sits in the other workspace but also inside the window.
        $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', ['2025-03-01 10:00:00', $ids[2]]);

        $viewer = $this->createAgent('Viewer');
        $this->token($viewer->getId(), 'viewer');
        $workspace = $this->em->getRepository(\App\Administracion\EspacioDeTrabajo::class)->findOneBy(['phoneNumberId' => 'probe-ws']);
        $viewer->addEspacioDeTrabajo($workspace);
        $viewer->setEspacioDeTrabajoActivo($workspace);
        $this->em->flush();
        $agentToken = $this->loginAs('probe-agent2@characterization.local', ['ROLE_AGENT'], $viewer);
        $supToken = $this->loginAs('probe-sup2@characterization.local', ['ROLE_SUPERVISOR']);

        $this->assertBody(200, [], $this->request('GET', '/api/reports/recurring-customers', $agentToken));
        $this->assertBody(200, [[
            'contact_id' => 'repeat', 'contact_name' => 'Repeat', 'email' => null, 'ticket_count' => 1, 'last_ticket_at' => $recent,
        ]], $this->request('GET', '/api/reports/recurring-customers?threshold=1', $agentToken));
        $this->assertBody(200, [[
            'contact_id' => 'repeat', 'contact_name' => 'Repeat', 'email' => null, 'ticket_count' => 2, 'last_ticket_at' => $recent,
        ]], $this->request('GET', '/api/reports/recurring-customers?threshold=2', $supToken));
        $this->assertBody(200, [], $this->request('GET', '/api/reports/recurring-customers', $supToken));

        $reopened = static fn (string $t): array => [
            'ticket_id' => $t, 'contact_name' => 'Repeat', 'resolved_at' => '2025-03-01 12:00:00', 'reopened_at' => '2025-03-02 11:00:00', 'current_status' => 'resolved',
        ];
        $this->assertBody(200, [$reopened('r0')], $this->request('GET', '/api/reports/reopened?' . self::WINDOW, $agentToken));
        $supReopened = $this->tokenize($this->json($this->request('GET', '/api/reports/reopened?' . self::WINDOW, $supToken)));
        self::assertCount(2, $supReopened);

        $delay = static fn (int $samples): array => [
            'data_available_from' => '2025-03-01 10:00:00',
            'partial_data' => true,
            'aggregate' => ['avg_delay_seconds' => 120, 'sample_count' => $samples, 'unanswered_inbound_count' => 0],
            'per_agent' => [['agent_id' => 'owner', 'agent_name' => 'Owner', 'avg_delay_seconds' => 120, 'sample_count' => $samples]],
        ];
        $this->assertBody(200, $delay(1), $this->request('GET', '/api/reports/response-delay?' . self::WINDOW, $agentToken));
        $this->assertBody(200, $delay(2), $this->request('GET', '/api/reports/response-delay?' . self::WINDOW, $supToken));
    }

    public function testUnassignedIsCappedAtFiftyNewestFirst(): void
    {
        $expected = [];
        for ($i = 0; $i < 55; ++$i) {
            $ticket = $this->createTicket('new', null, $this->createContact('+549110009' . \sprintf('%04d', $i)));
            $this->token($ticket->getId(), 'u' . $i);
            $createdAt = (new \DateTimeImmutable('2025-03-01 00:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s');
            $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', [$createdAt, $ticket->getId()]);
            $expected[] = ['id' => 'u' . $i, 'contact_name' => null, 'created_at' => $createdAt, 'priority' => 'medium'];
        }
        $token = $this->loginAs('probe-cap@characterization.local', ['ROLE_SUPERVISOR']);

        $this->assertBody(200, ['count' => 0, 'tickets' => \array_slice(\array_reverse($expected), 0, 50)], $this->request('GET', '/api/reports/unassigned', $token));
    }

    public function testReopenedIsCappedAtOneHundredNewestFirst(): void
    {
        $ticket = $this->createTicket('resolved');
        $this->token($ticket->getId(), 'r');
        $this->connection()->executeStatement('UPDATE tickets SET created_at = ?, resolved_at = ? WHERE id = ?', ['2025-02-01 10:00:00', '2025-02-01 12:00:00', $ticket->getId()]);
        $expected = [];
        for ($i = 0; $i < 105; ++$i) {
            $at = (new \DateTimeImmutable('2025-03-01 00:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s');
            $this->connection()->executeStatement(
                "INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, 'auto_reopened', 'system', ?)",
                [$ticket->getId(), $at],
            );
            $expected[] = ['ticket_id' => 'r', 'contact_name' => null, 'resolved_at' => '2025-02-01 12:00:00', 'reopened_at' => $at, 'current_status' => 'resolved'];
        }
        $token = $this->loginAs('probe-reopen@characterization.local', ['ROLE_SUPERVISOR']);

        $this->assertBody(200, \array_slice(\array_reverse($expected), 0, 100), $this->request('GET', '/api/reports/reopened?' . self::WINDOW, $token));
    }

    public function testParticipationNotFound(): void
    {
        $token = $this->loginAs('probe-part@characterization.local', ['ROLE_SUPERVISOR']);

        $this->assertBody(404, ['error' => 'Ticket not found'], $this->request('GET', '/api/reports/tickets/999999999/participation', $token));
    }

    public function testParticipationWithAnUnpromptedReplyAndANextTicket(): void
    {
        $owner = $this->createAgent('Owner');
        $helper = $this->createAgent('Helper');
        $this->token($owner->getId(), 'owner');
        $this->token($helper->getId(), 'helper');
        $first = $this->createTicket('resolved', $owner->getId());
        $this->token($first->getId(), 'first');
        $conversationId = $first->getConversacion()->getId();
        $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', ['2025-03-01 10:00:00', $first->getId()]);
        // A later ticket on the same conversation closes the first episode.
        $second = $this->createTicket('active', $owner->getId(), $first->getContacto());
        $this->connection()->executeStatement('UPDATE tickets SET created_at = ?, conversation_id = ? WHERE id = ?', ['2025-03-01 11:00:00', $conversationId, $second->getId()]);
        foreach ([
            ['entrante', 'contacto', null, '2025-03-01 10:00:00'],
            ['saliente', 'agente', $owner->getId(), '2025-03-01 10:03:00'],
            ['saliente', 'agente', $helper->getId(), '2025-03-01 10:04:00'],
            ['saliente', 'automatico', null, '2025-03-01 10:05:00'],
            ['entrante', null, null, '2025-03-01 10:06:00'],
            ['entrante', 'contacto', null, '2025-03-01 11:30:00'],
        ] as [$direction, $sender, $agentId, $at]) {
            $this->connection()->executeStatement(
                "INSERT INTO messages (conversation_id, direction, content, created_at, sender_agent_id, sender_type) VALUES (?, ?, 'm', ?, ?, ?)",
                [$conversationId, $direction, $at, $agentId, $sender],
            );
        }
        $token = $this->loginAs('probe-part2@characterization.local', ['ROLE_SUPERVISOR']);

        $response = $this->request('GET', '/api/reports/tickets/' . $first->getId() . '/participation', $token);
        $this->assertBody(200, self::$participationExpected, $response);
    }

    /** @var array<string, mixed> */
    private static array $participationExpected = [
        'ticket_id' => 'first',
        'data_available_from' => '2025-03-01 10:00:00',
        'partial_data' => true,
        'participants' => [
            ['agent_id' => 'owner', 'agent_name' => 'Owner', 'message_count' => 1, 'sample_count' => 1, 'avg_delay_seconds' => 180],
            ['agent_id' => 'helper', 'agent_name' => 'Helper', 'message_count' => 1, 'sample_count' => 0, 'avg_delay_seconds' => null],
        ],
        'automatic_message_count' => 1,
        'unanswered_inbound_count' => 0,
        'pairs' => [
            ['run_start_at' => '2025-03-01 10:00:00', 'reply_at' => '2025-03-01 10:03:00', 'agent_id' => 'owner', 'delay_seconds' => 180],
        ],
    ];
}
