<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Conversacion\Mensaje;
use App\Conversacion\Query\GetMensajesDeTicketQuery;
use App\Ticket\Query\GetDetalleDeTicketQuery;
use App\Ticket\Ticket;

/**
 * Characterization of App\Ticket\Controller\BandejaController:
 * app_inbox_ticket_detail, app_inbox_ticket_messages, app_ticket_mark_read,
 * plus the generic 500 envelope (shape S1) of this controller.
 */
final class InboxControllerCharacterizationTest extends CharacterizationTestCase
{
    private const UTC_Z_DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private function addMessage(Ticket $ticket, string $direction, string $content): Mensaje
    {
        $message = new Mensaje();
        $message->setConversacion($ticket->getConversacion());
        $message->setDireccion($direction);
        $message->setContenido($content);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    // ── app_inbox_tickets ──

    public function testTicketListUsesTheContractKeysAndQueryNames(): void
    {
        $owner = $this->createAgent('Owner');
        $assigned = $this->createTicket('activo', $owner->getId());
        $unassigned = $this->createTicket('nuevo');
        $this->createTicket('en_espera');
        $token = $this->loginAs('inbox-list@characterization.local', ['ROLE_SUPERVISOR'], $this->createAgent('Sup'));

        $body = $this->json($this->request('GET', '/api/tickets?filtro=sin_asignar&estado=nuevo', $token));

        self::assertSame([$unassigned->getId()], \array_column($body['data'], 'ticketId'));
        self::assertSame(
            ['ticketId', 'estado', 'prioridad', 'contacto', 'ultimoMensaje', 'responsable', 'metricas', 'fechas'],
            \array_keys($body['data'][0]),
        );
        self::assertSame('nuevo', $body['data'][0]['estado']);
        self::assertSame('media', $body['data'][0]['prioridad']);
        self::assertSame(['id', 'telefono', 'nombre'], \array_keys($body['data'][0]['contacto']));
        self::assertNull($body['data'][0]['responsable']);
        self::assertSame(['cantidadDeNoLeidos' => 0], $body['data'][0]['metricas']);
        self::assertSame(['abiertoAt', 'actualizadoAt', 'ultimoMensajeEntranteAt'], \array_keys($body['data'][0]['fechas']));

        $mios = $this->json($this->request('GET', '/api/tickets?filtro=mios&agenteId=' . $owner->getId(), $token));
        self::assertSame([$assigned->getId()], \array_column($mios['data'], 'ticketId'));
        self::assertSame(['id', 'nombre', 'enTurno', 'cargaActual', 'capacidadMaxima'], \array_keys($mios['data'][0]['responsable']));
        self::assertSame($owner->getId(), $mios['data'][0]['responsable']['id']);

        // Los nombres y valores viejos de la query ya no filtran.
        $viejos = $this->json($this->request('GET', '/api/tickets?filter=unassigned&status=new', $token));
        self::assertSame(3, $viejos['meta']['total']);
    }

    // ── app_inbox_ticket_detail ──

    public function testTicketDetailShape(): void
    {
        $owner = $this->createAgent('Owner');
        $contact = $this->createContact('+5491100001111', 'Ana');
        $ticket = $this->createTicket('activo', $owner->getId(), $contact);
        $this->addMessage($ticket, 'entrante', 'hola');
        $this->addMessage($ticket, 'saliente', 'buenas');
        $token = $this->loginAs('inbox-owner@characterization.local', ['ROLE_AGENT'], $owner);

        $response = $this->request('GET', '/api/tickets/' . $ticket->getId(), $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(
            ['ticketId', 'estado', 'prioridad', 'contacto', 'responsable', 'fechas', 'metricas', 'ultimoMensaje'],
            \array_keys($body),
        );
        self::assertSame($ticket->getId(), $body['ticketId']);
        self::assertSame('activo', $body['estado']);
        self::assertSame('media', $body['prioridad']);
        self::assertSame(['id' => $contact->getId(), 'telefono' => '+5491100001111', 'nombre' => 'Ana'], $body['contacto']);
        self::assertSame(['id', 'nombre'], \array_keys($body['responsable']));
        // Characterization: current behavior, not necessarily desired.
        // The owner name is a hardcoded null placeholder.
        self::assertSame($owner->getId(), $body['responsable']['id']);
        self::assertNull($body['responsable']['nombre']);
        self::assertSame(['abiertoAt', 'asignadoAt', 'resueltoAt', 'cerradoAt', 'ultimoMensajeEntranteAt'], \array_keys($body['fechas']));
        self::assertMatchesRegularExpression(self::UTC_Z_DATETIME, $body['fechas']['abiertoAt']);
        // Characterization: closed_at is always null (hardcoded placeholder).
        self::assertNull($body['fechas']['cerradoAt']);
        self::assertSame(['cantidadDeNoLeidos' => 1], $body['metricas']);
        self::assertContains($body['ultimoMensaje'], ['hola', 'buenas']);
    }

    public function testTicketDetailOfAnotherAgentsTicketIsVisible(): void
    {
        $owner = $this->createAgent('Owner');
        $ticket = $this->createTicket('activo', $owner->getId());
        $token = $this->loginAs('inbox-other@characterization.local', ['ROLE_AGENT'], $this->createAgent('Other'));

        $response = $this->request('GET', '/api/tickets/' . $ticket->getId(), $token);

        // Characterization: current behavior, not necessarily desired.
        // The detail action applies no ownership or workspace scope.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($ticket->getId(), $this->json($response)['ticketId']);
    }

    public function testTicketDetailUnknownTicketReturns404(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $this->assertJsonResponse(404, ['error' => 'Ticket not found'], $this->request('GET', '/api/tickets/999999999', $token));
    }

    public function testTicketDetailUnexpectedFailureReturnsGenericEnvelope(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));
        $query = $this->createMock(GetDetalleDeTicketQuery::class);
        $query->method('execute')->willThrowException(new \RuntimeException('database exploded'));
        static::getContainer()->set(GetDetalleDeTicketQuery::class, $query);

        $response = $this->request('GET', '/api/tickets/1', $token);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertStringNotContainsString('database exploded', (string) $response->getContent());
    }

    // ── app_inbox_ticket_messages ──

    public function testMessagesBackwardPaginationWithCursor(): void
    {
        $ticket = $this->createTicket('activo');
        $m1 = $this->addMessage($ticket, 'entrante', 'one');
        $m2 = $this->addMessage($ticket, 'saliente', 'two');
        $m3 = $this->addMessage($ticket, 'entrante', 'three');
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $response = $this->request('GET', '/api/tickets/' . $ticket->getId() . '/mensajes?limit=2', $token);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(['data', 'meta'], \array_keys($body));
        self::assertSame(['hayMas', 'siguienteCursor'], \array_keys($body['meta']));
        self::assertTrue($body['meta']['hayMas']);
        // Newest two, in chronological order.
        self::assertSame([$m2->getId(), $m3->getId()], \array_column($body['data'], 'mensajeId'));
        self::assertSame(
            ['mensajeId', 'direccion', 'contenido', 'creadoAt', 'adjunto', 'agenteRemitenteId', 'tipoDeRemitente'],
            \array_keys($body['data'][0]),
        );
        self::assertSame('saliente', $body['data'][0]['direccion']);
        self::assertSame('two', $body['data'][0]['contenido']);
        self::assertMatchesRegularExpression(self::UTC_Z_DATETIME, $body['data'][0]['creadoAt']);
        self::assertNull($body['data'][0]['adjunto']);
        self::assertNull($body['data'][0]['agenteRemitenteId']);
        self::assertNull($body['data'][0]['tipoDeRemitente']);

        $cursor = \json_decode(\base64_decode($body['meta']['siguienteCursor'], true), true);
        self::assertSame(['created_at', 'id'], \array_keys($cursor));
        self::assertSame($m2->getId(), $cursor['id']);

        $next = $this->json($this->request('GET', '/api/tickets/' . $ticket->getId() . '/mensajes?limit=2&cursor=' . \rawurlencode($body['meta']['siguienteCursor']), $token));
        self::assertSame([$m1->getId()], \array_column($next['data'], 'mensajeId'));
        self::assertSame(['hayMas' => false], $next['meta']);
    }

    public function testMessagesForwardDirection(): void
    {
        $ticket = $this->createTicket('activo');
        $m1 = $this->addMessage($ticket, 'entrante', 'one');
        $m2 = $this->addMessage($ticket, 'entrante', 'two');
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $body = $this->json($this->request('GET', '/api/tickets/' . $ticket->getId() . '/mensajes?sentido=adelante', $token));

        self::assertSame([$m1->getId(), $m2->getId()], \array_column($body['data'], 'mensajeId'));
        self::assertSame(['hayMas' => false], $body['meta']);
    }

    public function testMessagesOfUnknownTicketReturnEmptyListNot404(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $response = $this->request('GET', '/api/tickets/999999999/mensajes', $token);

        // Characterization: current behavior, not necessarily desired.
        // An unknown ticket gives 200 with an empty page, not 404.
        $this->assertJsonResponse(200, ['data' => [], 'meta' => ['hayMas' => false]], $response);
    }

    public function testMessagesInvalidArgumentFromQueryReturns400(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));
        $query = $this->createMock(GetMensajesDeTicketQuery::class);
        $query->method('execute')->willThrowException(new \InvalidArgumentException('bad cursor'));
        static::getContainer()->set(GetMensajesDeTicketQuery::class, $query);

        $this->assertJsonResponse(400, ['error' => 'bad cursor'], $this->request('GET', '/api/tickets/1/mensajes', $token));
    }

    // ── app_ticket_mark_read ──

    public function testOwnerMarksReadAndAnOutboxRowIsInserted(): void
    {
        $owner = $this->createAgent('Owner');
        $ticket = $this->createTicket('activo', $owner->getId());
        $token = $this->loginAs('inbox-owner@characterization.local', ['ROLE_AGENT'], $owner);

        $response = $this->request('PUT', '/api/tickets/' . $ticket->getId() . '/marcar-leido', $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertNotNull($this->connection()->fetchOne('SELECT last_read_at FROM tickets WHERE id = ?', [$ticket->getId()]));

        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM outbox_events');
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('ticket', $row['aggregate_type']);
        self::assertSame($ticket->getId(), (int) $row['aggregate_id']);
        self::assertSame('tickets', $row['topic']);
        self::assertSame('ticket.leido', $row['event_type']);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['sequence']);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $row['id']);
        // camelCase payload keys, unlike the snake_case payloads of other writers.
        $payload = \json_decode($row['payload'], true);
        \ksort($payload); // the JSON column does not keep key order
        self::assertSame(['eventType' => 'ticket.leido', 'ticketId' => $ticket->getId()], $payload);
    }

    public function testNonOwnerMarkReadIsANoOp(): void
    {
        $owner = $this->createAgent('Owner');
        $ticket = $this->createTicket('activo', $owner->getId());
        $token = $this->loginAs('inbox-other@characterization.local', ['ROLE_AGENT'], $this->createAgent('Other'));

        $response = $this->request('PUT', '/api/tickets/' . $ticket->getId() . '/marcar-leido', $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertNull($this->connection()->fetchOne('SELECT last_read_at FROM tickets WHERE id = ?', [$ticket->getId()]));
        self::assertSame(0, $this->countRows('outbox_events'));
    }

    public function testMarkReadOfUnassignedTicketByAdminWithoutAgentIsANoOp(): void
    {
        $ticket = $this->createTicket('nuevo');
        $token = $this->loginAs('inbox-admin@characterization.local', ['ROLE_ADMIN']);

        $response = $this->request('PUT', '/api/tickets/' . $ticket->getId() . '/marcar-leido', $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, $this->countRows('outbox_events'));
    }

    public function testMarkReadUnknownTicketReturns404(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $this->assertJsonResponse(404, ['error' => 'Ticket not found'], $this->request('PUT', '/api/tickets/999999999/marcar-leido', $token));
        self::assertSame(0, $this->countRows('outbox_events'));
    }
}
