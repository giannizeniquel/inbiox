<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Compartido\Realtime\MercureJwtService;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Uid\Ulid;

/**
 * Characterization of App\Compartido\Controller\TicketEventsController
 * (app_ticket_events_resync, GET /api/tickets/{id}/events).
 *
 * The request needs BOTH the app JWT (firewall, ROLE_AGENT via access_control)
 * and a Mercure subscriber JWT in the mercureAuthorization cookie.
 */
final class TicketEventsControllerCharacterizationTest extends CharacterizationTestCase
{
    private const TICKET_ID = 987654321;

    private function appToken(): string
    {
        return $this->loginAs('events@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));
    }

    private function mercureToken(array $topics): string
    {
        return static::getContainer()->get(MercureJwtService::class)->createSubscriberToken($topics)->toString();
    }

    private function setMercureCookie(string $token): void
    {
        $this->client->getCookieJar()->set(new Cookie('mercureAuthorization', $token));
    }

    private function insertOutboxRow(int $aggregateId, int $sequence, string $status, string $aggregateType = 'ticket'): string
    {
        $id = (string) new Ulid();
        $this->connection()->insert('outbox_events', [
            'id' => $id,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'topic' => 'tickets',
            'event_type' => 'ticket.read',
            'payload' => \json_encode(['eventType' => 'ticket.read', 'ticketId' => $aggregateId]),
            'created_at' => '2026-01-01 10:00:00',
            'status' => $status,
            'sequence' => $sequence,
        ]);

        return $id;
    }

    public function testResyncReturnsPendingAndPublishedEventsAfterTheSequence(): void
    {
        $this->insertOutboxRow(self::TICKET_ID, 0, 'pending');
        $published = $this->insertOutboxRow(self::TICKET_ID, 1, 'published');
        $pending = $this->insertOutboxRow(self::TICKET_ID, 2, 'pending');
        $this->insertOutboxRow(self::TICKET_ID, 3, 'failed');
        $this->insertOutboxRow(self::TICKET_ID + 1, 4, 'pending');
        $appToken = $this->appToken();
        $this->setMercureCookie($this->mercureToken(['tickets']));

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events', $appToken);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(['events', 'lastSequence', 'hasMore'], \array_keys($body));
        self::assertSame(2, $body['lastSequence']);
        self::assertFalse($body['hasMore']);
        self::assertCount(2, $body['events']);
        self::assertSame([
            'eventId' => $published,
            'sequence' => 1,
            'timestamp' => (new \DateTimeImmutable('2026-01-01 10:00:00'))->format(\DateTimeInterface::RFC3339),
            'eventType' => 'ticket.read',
            'ticketId' => (string) self::TICKET_ID,
            'payload' => ['ticketId' => self::TICKET_ID],
        ], $body['events'][0]);
        self::assertSame($pending, $body['events'][1]['eventId']);
    }

    public function testResyncFromMinusOneIncludesSequenceZero(): void
    {
        $this->insertOutboxRow(self::TICKET_ID, 0, 'pending');
        $appToken = $this->appToken();
        $this->setMercureCookie($this->mercureToken(['tickets']));

        $body = $this->json($this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events?afterSequence=-1', $appToken));

        self::assertCount(1, $body['events']);
        self::assertSame(0, $body['lastSequence']);
    }

    public function testResyncWithNothingNewEchoesTheRequestedSequence(): void
    {
        $appToken = $this->appToken();
        $this->setMercureCookie($this->mercureToken(['tickets']));

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events?afterSequence=7', $appToken);

        $this->assertJsonResponse(200, ['events' => [], 'lastSequence' => 7, 'hasMore' => false], $response);
    }

    public function testResyncIgnoresTheAggregateType(): void
    {
        $this->insertOutboxRow(self::TICKET_ID, 1, 'pending', 'agent');
        $appToken = $this->appToken();
        $this->setMercureCookie($this->mercureToken(['tickets']));

        $body = $this->json($this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events', $appToken));

        // Characterization: current behavior, not necessarily desired.
        // The query filters by aggregate_id only, so a non-ticket aggregate
        // with the same id is returned as a ticket event.
        self::assertCount(1, $body['events']);
    }

    public function testMissingMercureCookieReturns401(): void
    {
        $appToken = $this->appToken();

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events', $appToken);

        $this->assertJsonResponse(401, ['error' => 'Unauthorized — missing or invalid JWT'], $response);
    }

    public function testInvalidMercureCookieReturns401(): void
    {
        $appToken = $this->appToken();
        $this->setMercureCookie('not-a-jwt');

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events', $appToken);

        $this->assertJsonResponse(401, ['error' => 'Unauthorized — missing or invalid JWT'], $response);
    }

    public function testMercureTokenWithoutTicketsTopicReturns403(): void
    {
        $appToken = $this->appToken();
        $this->setMercureCookie($this->mercureToken(['agents']));

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events', $appToken);

        $this->assertJsonResponse(403, ['error' => 'Forbidden — JWT does not grant access to this ticket'], $response);
    }

    public function testAfterSequenceBelowMinusOneReturns400(): void
    {
        $appToken = $this->appToken();
        $this->setMercureCookie($this->mercureToken(['tickets']));

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events?afterSequence=-2', $appToken);

        $this->assertJsonResponse(400, ['error' => 'afterSequence must be >= -1'], $response);
    }

    public function testMercureTokenAsBearerIsRejectedByTheFirewall(): void
    {
        $this->appToken();

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events', $this->mercureToken(['tickets']));

        // Characterization: current behavior, not necessarily desired.
        // The controller's Authorization-header branch is unreachable: the
        // firewall rejects the Mercure JWT before the controller runs.
        $this->assertJsonResponse(401, ['code' => 401, 'message' => 'Invalid JWT Token'], $response);
    }

    public function testWithoutAppTokenTheFirewallRejects(): void
    {
        $this->setMercureCookie($this->mercureToken(['tickets']));

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events', null);

        $this->assertJsonResponse(401, ['code' => 401, 'message' => 'JWT Token not found'], $response);
    }
}
