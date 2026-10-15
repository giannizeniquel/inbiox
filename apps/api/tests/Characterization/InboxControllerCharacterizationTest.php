<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Conversacion\Message;
use App\Conversacion\Query\GetTicketMessagesQuery;
use App\Ticket\Query\GetTicketDetailQuery;
use App\Ticket\Ticket;

/**
 * Characterization of App\Ticket\Controller\InboxController:
 * app_inbox_ticket_detail, app_inbox_ticket_messages, app_ticket_mark_read,
 * plus the generic 500 envelope (shape S1) of this controller.
 */
final class InboxControllerCharacterizationTest extends CharacterizationTestCase
{
    private const UTC_Z_DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private function addMessage(Ticket $ticket, string $direction, string $content): Message
    {
        $message = new Message();
        $message->setConversation($ticket->getConversation());
        $message->setDirection($direction);
        $message->setContent($content);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    // ── app_inbox_ticket_detail ──

    public function testTicketDetailShape(): void
    {
        $owner = $this->createAgent('Owner');
        $contact = $this->createContact('+5491100001111', 'Ana');
        $ticket = $this->createTicket('active', $owner->getId(), $contact);
        $this->addMessage($ticket, 'inbound', 'hola');
        $this->addMessage($ticket, 'outbound', 'buenas');
        $token = $this->loginAs('inbox-owner@characterization.local', ['ROLE_AGENT'], $owner);

        $response = $this->request('GET', '/api/tickets/' . $ticket->getId(), $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(
            ['ticket_id', 'status', 'priority', 'contact', 'owner', 'timestamps', 'metrics', 'last_message'],
            \array_keys($body),
        );
        self::assertSame($ticket->getId(), $body['ticket_id']);
        self::assertSame('active', $body['status']);
        self::assertSame('medium', $body['priority']);
        self::assertSame(['id' => $contact->getId(), 'phone' => '+5491100001111', 'name' => 'Ana'], $body['contact']);
        self::assertSame(['id', 'name'], \array_keys($body['owner']));
        // Characterization: current behavior, not necessarily desired.
        // The owner name is a hardcoded null placeholder.
        self::assertSame($owner->getId(), $body['owner']['id']);
        self::assertNull($body['owner']['name']);
        self::assertSame(['opened_at', 'assigned_at', 'resolved_at', 'closed_at', 'last_inbound_message_at'], \array_keys($body['timestamps']));
        self::assertMatchesRegularExpression(self::UTC_Z_DATETIME, $body['timestamps']['opened_at']);
        // Characterization: closed_at is always null (hardcoded placeholder).
        self::assertNull($body['timestamps']['closed_at']);
        self::assertSame(['unread_count' => 1], $body['metrics']);
        self::assertContains($body['last_message'], ['hola', 'buenas']);
    }

    public function testTicketDetailOfAnotherAgentsTicketIsVisible(): void
    {
        $owner = $this->createAgent('Owner');
        $ticket = $this->createTicket('active', $owner->getId());
        $token = $this->loginAs('inbox-other@characterization.local', ['ROLE_AGENT'], $this->createAgent('Other'));

        $response = $this->request('GET', '/api/tickets/' . $ticket->getId(), $token);

        // Characterization: current behavior, not necessarily desired.
        // The detail action applies no ownership or workspace scope.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($ticket->getId(), $this->json($response)['ticket_id']);
    }

    public function testTicketDetailUnknownTicketReturns404(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $this->assertJsonResponse(404, ['error' => 'Ticket not found'], $this->request('GET', '/api/tickets/999999999', $token));
    }

    public function testTicketDetailUnexpectedFailureReturnsGenericEnvelope(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));
        $query = $this->createMock(GetTicketDetailQuery::class);
        $query->method('execute')->willThrowException(new \RuntimeException('database exploded'));
        static::getContainer()->set(GetTicketDetailQuery::class, $query);

        $response = $this->request('GET', '/api/tickets/1', $token);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertStringNotContainsString('database exploded', (string) $response->getContent());
    }

    // ── app_inbox_ticket_messages ──

    public function testMessagesBackwardPaginationWithCursor(): void
    {
        $ticket = $this->createTicket('active');
        $m1 = $this->addMessage($ticket, 'inbound', 'one');
        $m2 = $this->addMessage($ticket, 'outbound', 'two');
        $m3 = $this->addMessage($ticket, 'inbound', 'three');
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $response = $this->request('GET', '/api/tickets/' . $ticket->getId() . '/messages?limit=2', $token);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(['data', 'meta'], \array_keys($body));
        self::assertSame(['has_more', 'next_cursor'], \array_keys($body['meta']));
        self::assertTrue($body['meta']['has_more']);
        // Newest two, in chronological order.
        self::assertSame([$m2->getId(), $m3->getId()], \array_column($body['data'], 'message_id'));
        self::assertSame(
            ['message_id', 'direction', 'content', 'created_at', 'media', 'sender_agent_id', 'sender_type'],
            \array_keys($body['data'][0]),
        );
        self::assertSame('outbound', $body['data'][0]['direction']);
        self::assertSame('two', $body['data'][0]['content']);
        self::assertMatchesRegularExpression(self::UTC_Z_DATETIME, $body['data'][0]['created_at']);
        self::assertNull($body['data'][0]['media']);
        self::assertNull($body['data'][0]['sender_agent_id']);
        self::assertNull($body['data'][0]['sender_type']);

        $cursor = \json_decode(\base64_decode($body['meta']['next_cursor'], true), true);
        self::assertSame(['created_at', 'id'], \array_keys($cursor));
        self::assertSame($m2->getId(), $cursor['id']);

        $next = $this->json($this->request('GET', '/api/tickets/' . $ticket->getId() . '/messages?limit=2&cursor=' . \rawurlencode($body['meta']['next_cursor']), $token));
        self::assertSame([$m1->getId()], \array_column($next['data'], 'message_id'));
        self::assertSame(['has_more' => false], $next['meta']);
    }

    public function testMessagesForwardDirection(): void
    {
        $ticket = $this->createTicket('active');
        $m1 = $this->addMessage($ticket, 'inbound', 'one');
        $m2 = $this->addMessage($ticket, 'inbound', 'two');
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $body = $this->json($this->request('GET', '/api/tickets/' . $ticket->getId() . '/messages?direction=forward', $token));

        self::assertSame([$m1->getId(), $m2->getId()], \array_column($body['data'], 'message_id'));
        self::assertSame(['has_more' => false], $body['meta']);
    }

    public function testMessagesOfUnknownTicketReturnEmptyListNot404(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $response = $this->request('GET', '/api/tickets/999999999/messages', $token);

        // Characterization: current behavior, not necessarily desired.
        // An unknown ticket gives 200 with an empty page, not 404.
        $this->assertJsonResponse(200, ['data' => [], 'meta' => ['has_more' => false]], $response);
    }

    public function testMessagesInvalidArgumentFromQueryReturns400(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));
        $query = $this->createMock(GetTicketMessagesQuery::class);
        $query->method('execute')->willThrowException(new \InvalidArgumentException('bad cursor'));
        static::getContainer()->set(GetTicketMessagesQuery::class, $query);

        $this->assertJsonResponse(400, ['error' => 'bad cursor'], $this->request('GET', '/api/tickets/1/messages', $token));
    }

    // ── app_ticket_mark_read ──

    public function testOwnerMarksReadAndAnOutboxRowIsInserted(): void
    {
        $owner = $this->createAgent('Owner');
        $ticket = $this->createTicket('active', $owner->getId());
        $token = $this->loginAs('inbox-owner@characterization.local', ['ROLE_AGENT'], $owner);

        $response = $this->request('PUT', '/api/tickets/' . $ticket->getId() . '/mark-read', $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertNotNull($this->connection()->fetchOne('SELECT last_read_at FROM tickets WHERE id = ?', [$ticket->getId()]));

        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM outbox_events');
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('ticket', $row['aggregate_type']);
        self::assertSame($ticket->getId(), (int) $row['aggregate_id']);
        self::assertSame('tickets', $row['topic']);
        self::assertSame('ticket.read', $row['event_type']);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['sequence']);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $row['id']);
        // camelCase payload keys, unlike the snake_case payloads of other writers.
        $payload = \json_decode($row['payload'], true);
        \ksort($payload); // the JSON column does not keep key order
        self::assertSame(['eventType' => 'ticket.read', 'ticketId' => $ticket->getId()], $payload);
    }

    public function testNonOwnerMarkReadIsANoOp(): void
    {
        $owner = $this->createAgent('Owner');
        $ticket = $this->createTicket('active', $owner->getId());
        $token = $this->loginAs('inbox-other@characterization.local', ['ROLE_AGENT'], $this->createAgent('Other'));

        $response = $this->request('PUT', '/api/tickets/' . $ticket->getId() . '/mark-read', $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertNull($this->connection()->fetchOne('SELECT last_read_at FROM tickets WHERE id = ?', [$ticket->getId()]));
        self::assertSame(0, $this->countRows('outbox_events'));
    }

    public function testMarkReadOfUnassignedTicketByAdminWithoutAgentIsANoOp(): void
    {
        $ticket = $this->createTicket('new');
        $token = $this->loginAs('inbox-admin@characterization.local', ['ROLE_ADMIN']);

        $response = $this->request('PUT', '/api/tickets/' . $ticket->getId() . '/mark-read', $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, $this->countRows('outbox_events'));
    }

    public function testMarkReadUnknownTicketReturns404(): void
    {
        $token = $this->loginAs('inbox@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));

        $this->assertJsonResponse(404, ['error' => 'Ticket not found'], $this->request('PUT', '/api/tickets/999999999/mark-read', $token));
        self::assertSame(0, $this->countRows('outbox_events'));
    }
}
