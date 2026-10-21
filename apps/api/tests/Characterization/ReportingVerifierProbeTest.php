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
    private const WINDOW = 'desde=2025-03-01T00:00:00Z&hasta=2025-03-03T00:00:00Z';
    private const ID_KEYS = ['id', 'agenteId', 'ticketId', 'contactoId'];

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
            ['t1', $alpha, 'resuelto', 'probe-ws', 7, '2025-03-01 10:00:00', '2025-03-01 10:10:00', '2025-03-01 10:50:00'],
            ['t2', $alpha, 'activo', 'probe-ws', 8, '2025-03-01 12:00:00', '2025-03-01 12:20:00', null],
            ['t3', $beta, 'resuelto', 'probe-ws', 7, '2025-03-02 09:00:00', '2025-03-02 09:30:00', '2025-03-02 10:10:00'],
            ['t4', $alpha, 'en_espera', 'other-ws', 7, '2025-03-02 10:00:00', '2025-03-02 10:40:00', null],
            ['t5', $beta, 'nuevo', null, null, '2025-03-02 11:00:00', null, null],
            ['t6', null, 'nuevo', 'probe-ws', 7, '2025-03-02 13:00:00', null, null],
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

        return $this->loginAs('probe-agent@characterization.local', ['ROLE_AGENTE'], $viewer);
    }

    private function agentRow(string $agent, string $name, int $assigned, int $resolved, int $active, float|int $load, mixed $frt, mixed $rt): array
    {
        return [
            'agenteId' => $agent,
            'agenteNombre' => $name,
            'ticketsAsignados' => $assigned,
            'ticketsResueltos' => $resolved,
            'ticketsActivosActuales' => $active,
            'maximoDeTicketsActivos' => 10,
            'cargaActual' => $load,
            'enTurno' => false,
            'frtPromedioSegundos' => $frt,
            'frtPromedioMinutos' => $this->minutes($frt),
            'rtPromedioSegundos' => $rt,
            'rtPromedioMinutos' => $this->minutes($rt),
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
        $this->assertBody(200, $this->expectedFrt(), $this->request('GET', '/api/reportes/frt?' . self::WINDOW . '&agenteId=' . $alphaId . '&equipoId=' . $this->team7, $token));
        // team only
        $this->assertBody(200, [
            'rtPromedioSegundos' => 3600, 'rtMinimoSegundos' => 3000, 'rtMaximoSegundos' => 4200,
            'rtPromedioMinutos' => 60, 'rtMinimoMinutos' => 50, 'rtMaximoMinutos' => 70,
        ], $this->request('GET', '/api/reportes/rt?' . self::WINDOW . '&equipoId=' . $this->team7, $token));
        $this->assertBody(200, ['nuevo' => 1, 'activo' => 0, 'en_espera' => 1, 'resuelto' => 2, 'cerrado' => 0], $this->request('GET', '/api/reportes/conteos?' . self::WINDOW . '&equipoId=' . $this->team7, $token));
        $this->assertBody(200, [
            ['fecha' => '2025-03-01', 'ticketsCreados' => 1, 'ticketsResueltos' => 1],
            ['fecha' => '2025-03-02', 'ticketsCreados' => 3, 'ticketsResueltos' => 1],
        ], $this->request('GET', '/api/reportes/volumen?' . self::WINDOW . '&equipoId=' . $this->team7, $token));
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 2, 1, 1, 0.1, 1500, 3000),
            $this->agentRow('beta', 'Beta', 1, 1, 0, 0, 1800, 4200),
            $this->agentRow('viewer', 'Viewer', 0, 0, 0, 0, null, null),
        ], $this->request('GET', '/api/reportes/rendimiento-de-agentes?' . self::WINDOW . '&equipoId=' . $this->team7, $token));
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 3, 1, 2, 0.2, 1400, 3000),
        ], $this->request('GET', '/api/reportes/rendimiento-de-agentes?' . self::WINDOW . '&agenteId=' . $alphaId, $token));
        // No filter at all.
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 3, 1, 2, 0.2, 1400, 3000),
            $this->agentRow('beta', 'Beta', 2, 1, 1, 0.1, 1800, 4200),
            $this->agentRow('viewer', 'Viewer', 0, 0, 0, 0, null, null),
        ], $this->request('GET', '/api/reportes/rendimiento-de-agentes?' . self::WINDOW, $token));
    }

    private function expectedFrt(): array
    {
        // alpha + team 7 in the window: t1 (600 s) and t4 (2400 s).
        return [
            'frtPromedioSegundos' => 1500, 'frtMinimoSegundos' => 600, 'frtMaximoSegundos' => 2400,
            'frtPromedioMinutos' => 25, 'frtMinimoMinutos' => 10, 'frtMaximoMinutos' => 40,
        ];
    }

    public function testEveryReportIsScopedToTheAgentsActiveWorkspace(): void
    {
        $token = $this->seedFilterMatrix();
        $alphaId = \array_search('alpha', $this->tokens, true);

        $this->assertBody(200, [
            'frtPromedioSegundos' => 1200, 'frtMinimoSegundos' => 600, 'frtMaximoSegundos' => 1800,
            'frtPromedioMinutos' => 20, 'frtMinimoMinutos' => 10, 'frtMaximoMinutos' => 30,
        ], $this->request('GET', '/api/reportes/frt?' . self::WINDOW, $token));
        // workspace + agent + team on frt
        $this->assertBody(200, [
            'frtPromedioSegundos' => 600, 'frtMinimoSegundos' => 600, 'frtMaximoSegundos' => 600,
            'frtPromedioMinutos' => 10, 'frtMinimoMinutos' => 10, 'frtMaximoMinutos' => 10,
        ], $this->request('GET', '/api/reportes/frt?' . self::WINDOW . '&agenteId=' . $alphaId . '&equipoId=' . $this->team7, $token));
        $this->assertBody(200, [
            'rtPromedioSegundos' => 3600, 'rtMinimoSegundos' => 3000, 'rtMaximoSegundos' => 4200,
            'rtPromedioMinutos' => 60, 'rtMinimoMinutos' => 50, 'rtMaximoMinutos' => 70,
        ], $this->request('GET', '/api/reportes/rt?' . self::WINDOW, $token));
        $this->assertBody(200, ['nuevo' => 1, 'activo' => 1, 'en_espera' => 0, 'resuelto' => 2, 'cerrado' => 0], $this->request('GET', '/api/reportes/conteos?' . self::WINDOW, $token));
        $this->assertBody(200, [
            ['fecha' => '2025-03-01', 'ticketsCreados' => 2, 'ticketsResueltos' => 1],
            ['fecha' => '2025-03-02', 'ticketsCreados' => 2, 'ticketsResueltos' => 1],
        ], $this->request('GET', '/api/reportes/volumen?' . self::WINDOW, $token));
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 2, 1, 1, 0.1, 900, 3000),
            $this->agentRow('beta', 'Beta', 1, 1, 0, 0, 1800, 4200),
            $this->agentRow('viewer', 'Viewer', 0, 0, 0, 0, null, null),
        ], $this->request('GET', '/api/reportes/rendimiento-de-agentes?' . self::WINDOW, $token));
        $this->assertBody(200, [
            $this->agentRow('alpha', 'Alpha', 1, 1, 0, 0, 600, 3000),
        ], $this->request('GET', '/api/reportes/rendimiento-de-agentes?' . self::WINDOW . '&agenteId=' . $alphaId . '&equipoId=' . $this->team7, $token));
        $this->assertBody(200, [
            ['agenteId' => 'alpha', 'agenteNombre' => 'Alpha', 'ticketsActivos' => 1, 'capacidadMaxima' => 10, 'porcentajeDeCarga' => 10],
            ['agenteId' => 'beta', 'agenteNombre' => 'Beta', 'ticketsActivos' => 0, 'capacidadMaxima' => 10, 'porcentajeDeCarga' => 0],
            ['agenteId' => 'viewer', 'agenteNombre' => 'Viewer', 'ticketsActivos' => 0, 'capacidadMaxima' => 10, 'porcentajeDeCarga' => 0],
        ], $this->request('GET', '/api/reportes/carga-de-agentes', $token));
        $this->assertBody(200, [
            ['agenteId' => 'alpha', 'agenteNombre' => 'Alpha', 'ticketsActivos' => 1, 'capacidadMaxima' => 10, 'porcentajeDeCarga' => 10],
        ], $this->request('GET', '/api/reportes/carga-de-agentes?agenteId=' . $alphaId, $token));
        $unassignedRow = ['id' => 't6', 'contactoNombre' => 'C-t6', 'creadoAt' => '2025-03-02 13:00:00', 'prioridad' => 'media'];
        $this->assertBody(200, ['cantidad' => 0, 'tickets' => [$unassignedRow]], $this->request('GET', '/api/reportes/sin-asignar', $token));
        $this->assertBody(200, ['cantidad' => 0, 'tickets' => [$unassignedRow]], $this->request('GET', '/api/reportes/sin-asignar?' . self::WINDOW, $token));
        $this->assertBody(200, ['cantidad' => 0, 'tickets' => []], $this->request('GET', '/api/reportes/sin-asignar?desde=2025-03-02T14:00:00Z', $token));
        $this->assertBody(200, [
            ['fecha' => '2025-03-01', 'frtPromedioSegundos' => 900, 'cantidadDeTickets' => 2],
            ['fecha' => '2025-03-02', 'frtPromedioSegundos' => 1800, 'cantidadDeTickets' => 1],
        ], $this->request('GET', '/api/reportes/tendencia-de-frt?' . self::WINDOW, $token));
        // 2025-03-01 is a Saturday (ISO 6), 2025-03-02 a Sunday (ISO 7).
        $this->assertBody(200, [
            ['diaDeLaSemana' => 6, 'hora' => 10, 'cantidad' => 1],
            ['diaDeLaSemana' => 6, 'hora' => 12, 'cantidad' => 1],
            ['diaDeLaSemana' => 7, 'hora' => 9, 'cantidad' => 1],
            ['diaDeLaSemana' => 7, 'hora' => 13, 'cantidad' => 1],
        ], $this->request('GET', '/api/reportes/mapa-de-calor?' . self::WINDOW, $token));
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
            $ticket = $this->createTicket('resuelto', $owner->getId(), $repeat);
            $ids[$i] = $ticket->getId();
            $this->token($ticket->getId(), 'r' . $i);
            $this->connection()->executeStatement(
                'UPDATE tickets SET phone_number_id = ?, created_at = ?, resolved_at = ? WHERE id = ?',
                [$phone, 0 === $i ? '2025-03-01 10:00:00' : $recent, '2025-03-01 12:00:00', $ticket->getId()],
            );
        }
        foreach ([0, 2] as $i) {
            $this->connection()->executeStatement(
                "INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, 'reapertura_automatica', 'system', '2025-03-02 11:00:00')",
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
        $agentToken = $this->loginAs('probe-agent2@characterization.local', ['ROLE_AGENTE'], $viewer);
        $supToken = $this->loginAs('probe-sup2@characterization.local', ['ROLE_SUPERVISOR']);

        $this->assertBody(200, [], $this->request('GET', '/api/reportes/contactos-recurrentes', $agentToken));
        $this->assertBody(200, [[
            'contactoId' => 'repeat', 'contactoNombre' => 'Repeat', 'email' => null, 'cantidadDeTickets' => 1, 'ultimoTicketAt' => $recent,
        ]], $this->request('GET', '/api/reportes/contactos-recurrentes?umbral=1', $agentToken));
        $this->assertBody(200, [[
            'contactoId' => 'repeat', 'contactoNombre' => 'Repeat', 'email' => null, 'cantidadDeTickets' => 2, 'ultimoTicketAt' => $recent,
        ]], $this->request('GET', '/api/reportes/contactos-recurrentes?umbral=2', $supToken));
        $this->assertBody(200, [], $this->request('GET', '/api/reportes/contactos-recurrentes', $supToken));

        $reopened = static fn (string $t): array => [
            'ticketId' => $t, 'contactoNombre' => 'Repeat', 'resueltoAt' => '2025-03-01 12:00:00', 'reabiertoAt' => '2025-03-02 11:00:00', 'estadoActual' => 'resuelto',
        ];
        $this->assertBody(200, [$reopened('r0')], $this->request('GET', '/api/reportes/reabiertos?' . self::WINDOW, $agentToken));
        $supReopened = $this->tokenize($this->json($this->request('GET', '/api/reportes/reabiertos?' . self::WINDOW, $supToken)));
        self::assertCount(2, $supReopened);

        $delay = static fn (int $samples): array => [
            'datosDisponiblesDesde' => '2025-03-01 10:00:00',
            'datosParciales' => true,
            'global' => ['demoraPromedioSegundos' => 120, 'cantidadDeMuestras' => $samples, 'cantidadDeEntrantesSinResponder' => 0],
            'porAgente' => [['agenteId' => 'owner', 'agenteNombre' => 'Owner', 'demoraPromedioSegundos' => 120, 'cantidadDeMuestras' => $samples]],
        ];
        $this->assertBody(200, $delay(1), $this->request('GET', '/api/reportes/demora-de-respuesta?' . self::WINDOW, $agentToken));
        $this->assertBody(200, $delay(2), $this->request('GET', '/api/reportes/demora-de-respuesta?' . self::WINDOW, $supToken));
    }

    public function testUnassignedIsCappedAtFiftyNewestFirst(): void
    {
        $expected = [];
        for ($i = 0; $i < 55; ++$i) {
            $ticket = $this->createTicket('nuevo', null, $this->createContact('+549110009' . \sprintf('%04d', $i)));
            $this->token($ticket->getId(), 'u' . $i);
            $createdAt = (new \DateTimeImmutable('2025-03-01 00:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s');
            $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', [$createdAt, $ticket->getId()]);
            $expected[] = ['id' => 'u' . $i, 'contactoNombre' => null, 'creadoAt' => $createdAt, 'prioridad' => 'media'];
        }
        $token = $this->loginAs('probe-cap@characterization.local', ['ROLE_SUPERVISOR']);

        $this->assertBody(200, ['cantidad' => 0, 'tickets' => \array_slice(\array_reverse($expected), 0, 50)], $this->request('GET', '/api/reportes/sin-asignar', $token));
    }

    public function testReopenedIsCappedAtOneHundredNewestFirst(): void
    {
        $ticket = $this->createTicket('resuelto');
        $this->token($ticket->getId(), 'r');
        $this->connection()->executeStatement('UPDATE tickets SET created_at = ?, resolved_at = ? WHERE id = ?', ['2025-02-01 10:00:00', '2025-02-01 12:00:00', $ticket->getId()]);
        $expected = [];
        for ($i = 0; $i < 105; ++$i) {
            $at = (new \DateTimeImmutable('2025-03-01 00:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s');
            $this->connection()->executeStatement(
                "INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, 'reapertura_automatica', 'system', ?)",
                [$ticket->getId(), $at],
            );
            $expected[] = ['ticketId' => 'r', 'contactoNombre' => null, 'resueltoAt' => '2025-02-01 12:00:00', 'reabiertoAt' => $at, 'estadoActual' => 'resuelto'];
        }
        $token = $this->loginAs('probe-reopen@characterization.local', ['ROLE_SUPERVISOR']);

        $this->assertBody(200, \array_slice(\array_reverse($expected), 0, 100), $this->request('GET', '/api/reportes/reabiertos?' . self::WINDOW, $token));
    }

    public function testParticipationNotFound(): void
    {
        $token = $this->loginAs('probe-part@characterization.local', ['ROLE_SUPERVISOR']);

        $this->assertBody(404, ['error' => 'Ticket not found'], $this->request('GET', '/api/reportes/tickets/999999999/participacion', $token));
    }

    public function testParticipationWithAnUnpromptedReplyAndANextTicket(): void
    {
        $owner = $this->createAgent('Owner');
        $helper = $this->createAgent('Helper');
        $this->token($owner->getId(), 'owner');
        $this->token($helper->getId(), 'helper');
        $first = $this->createTicket('resuelto', $owner->getId());
        $this->token($first->getId(), 'first');
        $conversationId = $first->getConversacion()->getId();
        $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', ['2025-03-01 10:00:00', $first->getId()]);
        // A later ticket on the same conversation closes the first episode.
        $second = $this->createTicket('activo', $owner->getId(), $first->getContacto());
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

        $response = $this->request('GET', '/api/reportes/tickets/' . $first->getId() . '/participacion', $token);
        $this->assertBody(200, self::$participationExpected, $response);
    }

    /** @var array<string, mixed> */
    private static array $participationExpected = [
        'ticketId' => 'first',
        'datosDisponiblesDesde' => '2025-03-01 10:00:00',
        'datosParciales' => true,
        'participantes' => [
            ['agenteId' => 'owner', 'agenteNombre' => 'Owner', 'cantidadDeMensajes' => 1, 'cantidadDeMuestras' => 1, 'demoraPromedioSegundos' => 180],
            ['agenteId' => 'helper', 'agenteNombre' => 'Helper', 'cantidadDeMensajes' => 1, 'cantidadDeMuestras' => 0, 'demoraPromedioSegundos' => null],
        ],
        'cantidadDeMensajesAutomaticos' => 1,
        'cantidadDeEntrantesSinResponder' => 0,
        'pares' => [
            ['inicioDeRachaAt' => '2025-03-01 10:00:00', 'respuestaAt' => '2025-03-01 10:03:00', 'agenteId' => 'owner', 'demoraSegundos' => 180],
        ],
    ];
}
