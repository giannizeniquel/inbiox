<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Asignacion\Asignacion;
use App\Ticket\Repository\TicketRepository;

/**
 * Characterization of App\Asignacion\Controller\AsignacionAutomaticaController
 * (app_ticket_auto_assign) and App\Asignacion\Controller\AsignacionDeTicketController::getAsignacion
 * (app_ticket_assignment).
 */
final class AsignacionControllersCharacterizationTest extends CharacterizationTestCase
{
    // Characterization: current behavior, not necessarily desired.
    // This writer stores the internal event names as event_type.
    private const EXPECTED_AUTO_ASSIGN_EVENT_TYPES = ['ticket.actualizado', 'ticket.bandeja_actualizada'];

    // ── app_ticket_auto_assign ──

    public function testAutoAssignPicksAnAvailableAgentAndWritesOutbox(): void
    {
        $available = $this->createAgent('Available');
        $available->setDisponibilidadManual(true);
        $this->em->flush();
        $ticket = $this->createTicket('new');
        // Any ROLE_AGENT may trigger auto-assignment of any ticket.
        $token = $this->loginAs('auto-caller@characterization.local', ['ROLE_AGENT']);

        $response = $this->request('POST', '/api/tickets/' . $ticket->getId() . '/auto-assign', $token);

        $this->assertJsonResponse(200, [
            'ticket_id' => $ticket->getId(),
            'assigned' => true,
            'agent_id' => $available->getId(),
            'reason' => 'success',
        ], $response);

        self::assertSame($available->getId(), (int) $this->connection()->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticket->getId()]));
        $assignment = $this->connection()->fetchAssociative('SELECT agent_id, assigned_by, type FROM assignments WHERE ticket_id = ?', [$ticket->getId()]);
        self::assertSame($available->getId(), (int) $assignment['agent_id']);
        self::assertSame('system', $assignment['assigned_by']);
        self::assertSame('AUTO_ASSIGN', $assignment['type']);

        $rows = $this->connection()->fetchAllAssociative('SELECT aggregate_type, aggregate_id, topic, event_type, payload, status, sequence FROM outbox_events ORDER BY sequence');
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame('ticket', $row['aggregate_type']);
            self::assertSame($ticket->getId(), (int) $row['aggregate_id']);
            self::assertSame('tickets', $row['topic']);
            self::assertSame('pending', $row['status']);
        }
        self::assertSame(self::EXPECTED_AUTO_ASSIGN_EVENT_TYPES, \array_column($rows, 'event_type'));

        $ticketUpdated = \json_decode($rows[0]['payload'], true);
        \ksort($ticketUpdated);
        self::assertSame(
            ['assignee_id', 'assignment_type', 'eventId', 'occurredAt', 'ownerAgentId', 'seq', 'status', 'ticketId', 'type', 'version'],
            \array_keys($ticketUpdated),
        );
        self::assertSame($available->getId(), $ticketUpdated['assignee_id']);
        self::assertSame($available->getId(), $ticketUpdated['ownerAgentId']);
        self::assertSame('AUTO_ASSIGN', $ticketUpdated['assignment_type']);
        self::assertSame($ticket->getId(), $ticketUpdated['ticketId']);
    }

    public function testAutoAssignWithoutAvailableAgents(): void
    {
        $ticket = $this->createTicket('new');
        $token = $this->loginAs('auto-caller@characterization.local', ['ROLE_AGENT']);

        $response = $this->request('POST', '/api/tickets/' . $ticket->getId() . '/auto-assign', $token);

        $this->assertJsonResponse(200, [
            'ticket_id' => $ticket->getId(),
            'assigned' => false,
            'agent_id' => null,
            'reason' => 'no_agents_available',
        ], $response);
        self::assertSame(0, $this->countRows('assignments'));
        self::assertSame(0, $this->countRows('outbox_events'));
    }

    public function testAutoAssignOfAnAlreadyAssignedTicketIsIdempotent(): void
    {
        $owner = $this->createAgent('Owner');
        $ticket = $this->createTicket('active', $owner->getId());
        $token = $this->loginAs('auto-caller@characterization.local', ['ROLE_AGENT']);

        $response = $this->request('POST', '/api/tickets/' . $ticket->getId() . '/auto-assign', $token);

        $this->assertJsonResponse(200, [
            'ticket_id' => $ticket->getId(),
            'assigned' => false,
            'agent_id' => $owner->getId(),
            'reason' => 'already_assigned',
        ], $response);
        self::assertSame(0, $this->countRows('assignments'));
    }

    public function testAutoAssignUnknownTicketReturns200NotFoundReason(): void
    {
        $token = $this->loginAs('auto-caller@characterization.local', ['ROLE_AGENT']);

        $response = $this->request('POST', '/api/tickets/999999999/auto-assign', $token);

        // Characterization: current behavior, not necessarily desired.
        // An unknown ticket is a 200 with a reason, not a 404.
        $this->assertJsonResponse(200, [
            'ticket_id' => 999999999,
            'assigned' => false,
            'agent_id' => null,
            'reason' => 'ticket_not_found',
        ], $response);
    }

    public function testAutoAssignNonPositiveIdReturns400(): void
    {
        $token = $this->loginAs('auto-caller@characterization.local', ['ROLE_AGENT']);

        $this->assertJsonResponse(400, ['error' => 'Invalid ticket ID'], $this->request('POST', '/api/tickets/0/auto-assign', $token));
    }

    public function testAutoAssignInvalidArgumentBecomesGeneric500(): void
    {
        $available = $this->createAgent('Available');
        $available->setDisponibilidadManual(true);
        $this->em->flush();
        $ticket = $this->createTicket('new');
        $token = $this->loginAs('auto-caller@characterization.local', ['ROLE_AGENT']);
        // AsignarTicketAutomaticamenteUseCase is final, so a collaborator it calls after
        // finding an available agent is replaced instead.
        $ticketRepo = $this->createMock(TicketRepository::class);
        $ticketRepo->method('countActivosDeAgente')->willThrowException(new \InvalidArgumentException('bad ticket'));
        // Phase 3c-1: the ticket lock now goes through TicketRepository, so forward it to the real repository.
        $realRepo = new TicketRepository(static::getContainer()->get(\Doctrine\Persistence\ManagerRegistry::class));
        $ticketRepo->method('findFilaByIdForUpdate')->willReturnCallback(fn (int $id) => $realRepo->findFilaByIdForUpdate($id));
        static::getContainer()->set(TicketRepository::class, $ticketRepo);

        $response = $this->request('POST', '/api/tickets/' . $ticket->getId() . '/auto-assign', $token);

        // Characterization: current behavior, not necessarily desired.
        // This controller has no InvalidArgumentException arm, so it is a 500.
        $this->assertInternalServerErrorEnvelope($response);
        self::assertStringNotContainsString('bad ticket', (string) $response->getContent());
        self::assertSame(0, $this->countRows('assignments'));
    }

    // ── app_ticket_assignment ──

    public function testAssignmentHistoryNewestFirst(): void
    {
        $first = $this->createAgent('First');
        $second = $this->createAgent('Second');
        $ticket = $this->createTicket('active', $second->getId());
        $older = new Asignacion($ticket, $first, 'system', null, 'AUTO_ASSIGN');
        $newer = new Asignacion($ticket, $second, 'supervisor@x', 'handover', 'MANUAL_ASSIGN', $first);
        $this->em->persist($older);
        $this->em->persist($newer);
        $this->em->flush();
        $this->connection()->executeStatement("UPDATE assignments SET assigned_at = '2026-01-01 10:00:00' WHERE id = ?", [$older->getId()]);
        $this->connection()->executeStatement("UPDATE assignments SET assigned_at = '2026-01-02 11:30:00' WHERE id = ?", [$newer->getId()]);
        $token = $this->loginAs('assignment@characterization.local', ['ROLE_AGENT'], $this->createAgent('Viewer'));

        $response = $this->request('GET', '/api/tickets/' . $ticket->getId() . '/assignment', $token);

        $this->assertJsonResponse(200, [
            'ticket_id' => $ticket->getId(),
            'assignments' => [
                ['id' => $newer->getId(), 'agent_id' => $second->getId(), 'assigned_by' => 'supervisor@x', 'assigned_at' => '2026-01-02 11:30:00', 'notes' => 'handover'],
                ['id' => $older->getId(), 'agent_id' => $first->getId(), 'assigned_by' => 'system', 'assigned_at' => '2026-01-01 10:00:00', 'notes' => null],
            ],
            'current_assignment' => ['agent_id' => $second->getId(), 'assigned_by' => 'supervisor@x', 'assigned_at' => '2026-01-02 11:30:00'],
        ], $response);
    }

    public function testAssignmentOfTicketWithoutHistory(): void
    {
        $ticket = $this->createTicket('new');
        $token = $this->loginAs('assignment@characterization.local', ['ROLE_AGENT'], $this->createAgent('Viewer'));

        $response = $this->request('GET', '/api/tickets/' . $ticket->getId() . '/assignment', $token);

        $this->assertJsonResponse(200, ['ticket_id' => $ticket->getId(), 'assignments' => [], 'current_assignment' => null], $response);
    }

    public function testAssignmentUnknownTicketReturns404(): void
    {
        $token = $this->loginAs('assignment@characterization.local', ['ROLE_AGENT'], $this->createAgent('Viewer'));

        $this->assertJsonResponse(404, ['error' => 'Ticket not found'], $this->request('GET', '/api/tickets/999999999/assignment', $token));
    }
}
