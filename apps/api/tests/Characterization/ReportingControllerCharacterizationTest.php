<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Agente\Agente;
use App\Disponibilidad\Turno;
use App\Reporte\Query\ReporteService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization of App\Reporte\Controller\ReporteController: every
 * JSON endpoint except export (covered by ReportsCsvExportTest), plus the
 * generic 500 envelope (shape S1, "no trace" variant).
 */
final class ReportingControllerCharacterizationTest extends CharacterizationTestCase
{
    private const WINDOW = 'desde=2025-03-01T00:00:00Z&hasta=2025-03-03T00:00:00Z';

    private Agente $owner;

    /**
     * Three tickets inside the window, all owned by one agent, and one outside it.
     */
    private function seedTickets(): void
    {
        $this->owner = $this->createAgent('Report Owner');
        $ownerId = $this->owner->getId();

        $resolved = $this->createTicket('resuelto', $ownerId);
        $closed = $this->createTicket('cerrado', $ownerId);
        $active = $this->createTicket('activo', $ownerId);
        $outside = $this->createTicket('nuevo');

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

        $response = $this->request('GET', '/api/reportes/rt?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, [
            'rtPromedioSegundos' => 5400,
            'rtMinimoSegundos' => 3600,
            'rtMaximoSegundos' => 7200,
            'rtPromedioMinutos' => 90,
            'rtMinimoMinutos' => 60,
            'rtMaximoMinutos' => 120,
        ], $response);
    }

    public function testResolutionTimeWithNoData(): void
    {
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            'rtPromedioSegundos' => null,
            'rtMinimoSegundos' => null,
            'rtMaximoSegundos' => null,
            'rtPromedioMinutos' => null,
            'rtMinimoMinutos' => null,
            'rtMaximoMinutos' => null,
        ], $this->request('GET', '/api/reportes/rt?' . self::WINDOW, $token));
    }

    // ── app_report_counts ──

    public function testCountsByStatus(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $response = $this->request('GET', '/api/reportes/conteos?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, ['nuevo' => 0, 'activo' => 1, 'en_espera' => 0, 'resuelto' => 1, 'cerrado' => 1], $response);
    }

    public function testCountsAreScopedToTheAgentsActiveWorkspace(): void
    {
        $this->seedTickets();
        $workspace = $this->createWorkspace('char-rep-ws', 'Reports');
        $this->connection()->executeStatement(
            "UPDATE tickets SET phone_number_id = 'char-rep-ws' WHERE status = 'activo'",
        );
        $agent = $this->createAgent('Scoped');
        $agent->setEspacioDeTrabajoActivo($workspace);
        $token = $this->loginAs('reports-agent@characterization.local', ['ROLE_AGENTE'], $agent);

        $response = $this->request('GET', '/api/reportes/conteos?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, ['nuevo' => 0, 'activo' => 1, 'en_espera' => 0, 'resuelto' => 0, 'cerrado' => 0], $response);
    }

    // ── app_report_volume ──

    public function testVolumePerDay(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $response = $this->request('GET', '/api/reportes/volumen?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, [
            ['fecha' => '2025-03-01', 'ticketsCreados' => 1, 'ticketsResueltos' => 1],
            ['fecha' => '2025-03-02', 'ticketsCreados' => 2, 'ticketsResueltos' => 1],
        ], $response);
    }

    // ── app_report_agents ──

    public function testAgentPerformance(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $response = $this->request('GET', '/api/reportes/rendimiento-de-agentes?' . self::WINDOW, $token);

        $this->assertJsonResponse(200, [[
            'agenteId' => $this->owner->getId(),
            'agenteNombre' => 'Report Owner',
            'ticketsAsignados' => 3,
            'ticketsResueltos' => 2,
            'ticketsActivosActuales' => 1,
            'maximoDeTicketsActivos' => 10,
            'cargaActual' => 0.1,
            'enTurno' => false,
            'frtPromedioSegundos' => 1800,
            'frtPromedioMinutos' => 30,
            'rtPromedioSegundos' => 5400,
            'rtPromedioMinutos' => 90,
        ]], $response);
    }

    public function testAgentPerformanceReportsAnAgentInShift(): void
    {
        $this->seedTickets();
        // A 00:00-00:00 shift is read as a cross-midnight shift that covers the
        // whole day, so one per weekday keeps the agent in shift at any time.
        foreach (range(1, 7) as $dayOfWeek) {
            $shift = new Turno($this->owner, $dayOfWeek, new \DateTimeImmutable('00:00:00'), new \DateTimeImmutable('00:00:00'));
            $this->owner->addTurno($shift);
            $this->em->persist($shift);
        }
        $this->em->flush();
        $token = $this->supervisorToken();

        $body = $this->json($this->request('GET', '/api/reportes/rendimiento-de-agentes?' . self::WINDOW, $token));

        self::assertTrue($body[0]['enTurno']);
        self::assertSame(['cargaActual', 'enTurno', 'frtPromedioSegundos'], \array_slice(\array_keys($body[0]), 6, 3));
    }

    public function testAgentPerformanceFilteredByUnknownAgentIsEmpty(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [], $this->request('GET', '/api/reportes/rendimiento-de-agentes?' . self::WINDOW . '&agenteId=999999999', $token));
    }

    // ── app_report_frt ──

    public function testFirstResponseTime(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            'frtPromedioSegundos' => 1800,
            'frtMinimoSegundos' => 1800,
            'frtMaximoSegundos' => 1800,
            'frtPromedioMinutos' => 30,
            'frtMinimoMinutos' => 30,
            'frtMaximoMinutos' => 30,
        ], $this->request('GET', '/api/reportes/frt?' . self::WINDOW, $token));
    }

    // ── app_report_agent_load ──

    public function testAgentLoad(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [[
            'agenteId' => $this->owner->getId(),
            'agenteNombre' => 'Report Owner',
            'ticketsActivos' => 1,
            'capacidadMaxima' => 10,
            'porcentajeDeCarga' => 10,
        ]], $this->request('GET', '/api/reportes/carga-de-agentes', $token));
    }

    // ── app_report_unassigned ──

    public function testUnassignedListsTicketsButCountsZero(): void
    {
        // The count is always 0 today (group F / R8); pinned as current behavior.
        $this->seedTickets();
        $outside = $this->connection()->fetchOne("SELECT id FROM tickets WHERE status = 'nuevo'");
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            'cantidad' => 0,
            'tickets' => [[
                'id' => (int) $outside,
                'contactoNombre' => null,
                'creadoAt' => '2025-04-01 10:00:00',
                'prioridad' => 'media',
            ]],
        ], $this->request('GET', '/api/reportes/sin-asignar', $token));
        $this->assertJsonResponse(200, ['cantidad' => 0, 'tickets' => []], $this->request('GET', '/api/reportes/sin-asignar?' . self::WINDOW, $token));
    }

    public function testUnassignedRejectsAMalformedDate(): void
    {
        $token = $this->supervisorToken();

        $this->assertJsonResponse(400, ['error' => 'Invalid datetime format: 2025-03-01. Expected: Y-m-d\TH:i:sZ'], $this->request('GET', '/api/reportes/sin-asignar?desde=2025-03-01', $token));
    }

    // ── app_report_available_agents ──

    public function testAvailableAgentsForASupervisor(): void
    {
        $this->seedTickets();
        $this->owner->setDisponibilidadManual(true);
        $this->createAgent('Off Override')->setDisponibilidadManual(false);
        $this->em->flush();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [[
            'agenteId' => $this->owner->getId(),
            'agenteNombre' => 'Report Owner',
            'enTurno' => true,
            'ticketsActivos' => 1,
        ]], $this->request('GET', '/api/reportes/agentes-disponibles', $token));
    }

    public function testAvailableAgentsAreFilteredByTheAgentsActiveWorkspace(): void
    {
        $this->seedTickets();
        $this->owner->setDisponibilidadManual(true);
        $workspace = $this->createWorkspace('char-rep-avail', 'Reports');
        $member = $this->createAgent('Workspace Member');
        $member->setDisponibilidadManual(true);
        $member->addEspacioDeTrabajo($workspace);
        $viewer = $this->createAgent('Viewer');
        $viewer->addEspacioDeTrabajo($workspace);
        $viewer->setEspacioDeTrabajoActivo($workspace);
        $this->em->flush();
        $token = $this->loginAs('reports-avail@characterization.local', ['ROLE_AGENTE'], $viewer);

        $this->assertJsonResponse(200, [[
            'agenteId' => $member->getId(),
            'agenteNombre' => 'Workspace Member',
            'enTurno' => true,
            'ticketsActivos' => 0,
        ]], $this->request('GET', '/api/reportes/agentes-disponibles', $token));
    }

    // ── app_report_frt_trend ──

    public function testFirstResponseTimeTrend(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [
            ['fecha' => '2025-03-01', 'frtPromedioSegundos' => 1800, 'cantidadDeTickets' => 1],
        ], $this->request('GET', '/api/reportes/tendencia-de-frt?' . self::WINDOW, $token));
    }

    // ── app_report_heatmap ──

    public function testHeatmap(): void
    {
        $this->seedTickets();
        $token = $this->supervisorToken();

        // 2025-03-01 is a Saturday (ISO 6), 2025-03-02 a Sunday (ISO 7).
        $this->assertJsonResponse(200, [
            ['diaDeLaSemana' => 6, 'hora' => 10, 'cantidad' => 1],
            ['diaDeLaSemana' => 7, 'hora' => 9, 'cantidad' => 1],
            ['diaDeLaSemana' => 7, 'hora' => 15, 'cantidad' => 1],
        ], $this->request('GET', '/api/reportes/mapa-de-calor?' . self::WINDOW, $token));
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
            $ticket = $this->createTicket('nuevo', null, $contact);
            $createdAt = (new \DateTimeImmutable('today'))->modify("-{$daysAgo} days")->format('Y-m-d H:i:s');
            $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', [$createdAt, $ticket->getId()]);
            $lastAt[$contact->getNombre()] ??= $createdAt;
        }
        $token = $this->supervisorToken();

        $frequentRow = [
            'contactoId' => $frequent->getId(),
            'contactoNombre' => 'Frequent',
            'email' => 'frequent@example.com',
            'cantidadDeTickets' => 3,
            'ultimoTicketAt' => $lastAt['Frequent'],
        ];
        $twiceRow = [
            'contactoId' => $twice->getId(),
            'contactoNombre' => 'Twice',
            'email' => null,
            'cantidadDeTickets' => 2,
            'ultimoTicketAt' => $lastAt['Twice'],
        ];

        $this->assertJsonResponse(200, [$frequentRow], $this->request('GET', '/api/reportes/contactos-recurrentes', $token));
        $this->assertJsonResponse(200, [$frequentRow, $twiceRow], $this->request('GET', '/api/reportes/contactos-recurrentes?umbral=2', $token));
        // umbral=0 is falsy and falls back to 3.
        $this->assertJsonResponse(200, [$frequentRow], $this->request('GET', '/api/reportes/contactos-recurrentes?umbral=0', $token));
    }

    // ── app_report_reopened ──

    public function testReopenedTickets(): void
    {
        $this->seedTickets();
        $resolvedId = (int) $this->connection()->fetchOne("SELECT id FROM tickets WHERE status = 'resuelto'");
        foreach ([['reapertura_automatica', '2025-03-02 11:00:00'], ['reapertura_automatica', '2025-03-05 11:00:00'], ['status_changed', '2025-03-02 12:00:00']] as [$type, $at]) {
            $this->connection()->executeStatement(
                "INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, ?, 'system', ?)",
                [$resolvedId, $type, $at],
            );
        }
        $token = $this->supervisorToken();

        $this->assertJsonResponse(200, [[
            'ticketId' => $resolvedId,
            'contactoNombre' => null,
            'resueltoAt' => '2025-03-01 12:00:00',
            'reabiertoAt' => '2025-03-02 11:00:00',
            'estadoActual' => 'resuelto',
        ]], $this->request('GET', '/api/reportes/reabiertos?' . self::WINDOW, $token));
    }

    // ── app_report_response_delay and app_report_ticket_participation ──

    /**
     * One ticket inside the window: a burst of two inbound messages answered
     * by the owner after 5 minutes, then an unanswered inbound message.
     */
    private function seedConversation(): int
    {
        $this->owner = $this->createAgent('Report Owner');
        $ticket = $this->createTicket('activo', $this->owner->getId());
        $this->connection()->executeStatement('UPDATE tickets SET created_at = ? WHERE id = ?', ['2025-03-01 10:00:00', $ticket->getId()]);
        $conversationId = $ticket->getConversacion()->getId();
        foreach ([
            ['entrante', 'contacto', null, '2025-03-01 10:00:00'],
            ['entrante', 'contacto', null, '2025-03-01 10:01:00'],
            ['saliente', 'agente', $this->owner->getId(), '2025-03-01 10:06:00'],
            ['entrante', 'contacto', null, '2025-03-01 10:10:00'],
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
            'datosDisponiblesDesde' => '2025-03-01 10:00:00',
            'datosParciales' => true,
            'global' => ['demoraPromedioSegundos' => 360, 'cantidadDeMuestras' => 1, 'cantidadDeEntrantesSinResponder' => 1],
            'porAgente' => [[
                'agenteId' => $this->owner->getId(),
                'agenteNombre' => 'Report Owner',
                'demoraPromedioSegundos' => 360,
                'cantidadDeMuestras' => 1,
            ]],
        ], $this->request('GET', '/api/reportes/demora-de-respuesta?' . self::WINDOW, $token));
    }

    public function testTicketParticipation(): void
    {
        $ticketId = $this->seedConversation();
        $token = $this->supervisorToken();

        $ownerId = $this->owner->getId();

        $this->assertJsonResponse(200, [
            'ticketId' => $ticketId,
            'datosDisponiblesDesde' => '2025-03-01 10:00:00',
            'datosParciales' => false,
            'participantes' => [[
                'agenteId' => $ownerId,
                'agenteNombre' => 'Report Owner',
                'cantidadDeMensajes' => 1,
                'cantidadDeMuestras' => 1,
                'demoraPromedioSegundos' => 360,
            ]],
            'cantidadDeMensajesAutomaticos' => 0,
            'cantidadDeEntrantesSinResponder' => 1,
            'pares' => [
                ['inicioDeRachaAt' => '2025-03-01 10:00:00', 'respuestaAt' => '2025-03-01 10:06:00', 'agenteId' => $ownerId, 'demoraSegundos' => 360],
                ['inicioDeRachaAt' => '2025-03-01 10:10:00', 'respuestaAt' => null, 'agenteId' => null, 'demoraSegundos' => null],
            ],
        ], $this->request('GET', '/api/reportes/tickets/' . $ticketId . '/participacion', $token));
    }

    // ── shared parameter errors ──

    #[DataProvider('reportRoutes')]
    public function testDateParameterErrors(string $route): void
    {
        $token = $this->supervisorToken();

        $this->assertJsonResponse(400, ['error' => 'Missing required parameter: desde or hasta'], $this->request('GET', $route . '?desde=2025-03-01T00:00:00Z', $token));
        $this->assertJsonResponse(400, ['error' => 'Invalid datetime format: 2025-03-01. Expected: Y-m-d\TH:i:sZ'], $this->request('GET', $route . '?desde=2025-03-01&hasta=2025-03-02T00:00:00Z', $token));
        $this->assertJsonResponse(400, ['error' => '"desde" must be before "hasta"'], $this->request('GET', $route . '?desde=2025-03-02T00:00:00Z&hasta=2025-03-01T00:00:00Z', $token));
    }

    public static function reportRoutes(): array
    {
        return [
            'rt' => ['/api/reportes/rt'],
            'counts' => ['/api/reportes/conteos'],
            'volume' => ['/api/reportes/volumen'],
            'agents' => ['/api/reportes/rendimiento-de-agentes'],
            'frt' => ['/api/reportes/frt'],
            'frt-trend' => ['/api/reportes/tendencia-de-frt'],
            'heatmap' => ['/api/reportes/mapa-de-calor'],
            'reopened' => ['/api/reportes/reabiertos'],
            'response-delay' => ['/api/reportes/demora-de-respuesta'],
        ];
    }

    public function testUnexpectedFailureReturnsGenericEnvelope(): void
    {
        $token = $this->supervisorToken();
        $service = $this->createMock(ReporteService::class);
        $service->method('getConteos')->willThrowException(new \RuntimeException('report exploded'));
        static::getContainer()->set(ReporteService::class, $service);

        $response = $this->request('GET', '/api/reportes/conteos?' . self::WINDOW, $token);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertStringNotContainsString('report exploded', (string) $response->getContent());
    }
}
