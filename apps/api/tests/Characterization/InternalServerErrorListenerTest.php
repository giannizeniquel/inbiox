<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Compartido\Realtime\MercureJwtService;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/**
 * Phase 3b: App\Compartido\EventListener\InternalServerErrorListener turns any
 * uncaught non-HTTP exception on an /api route into the generic 500 envelope
 * (shape S1 in odd/fase-3/errores.md), and leaves HTTP and security errors alone.
 */
final class InternalServerErrorListenerTest extends CharacterizationTestCase
{
    private const TICKET_ID = 987654322;

    private function agentToken(): string
    {
        return $this->loginAs('listener@characterization.local', ['ROLE_AGENT'], $this->createAgent('Agent'));
    }

    /**
     * The stable part of a framework-rendered (problem+json) error. The `trace`
     * key depends on the debug flag and is not pinned.
     *
     * @return array<string, mixed>
     */
    private function problemWithoutTrace(Response $response): array
    {
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $body = $this->json($response);
        unset($body['trace']);

        return $body;
    }

    public function testUncaughtExceptionInAnActionWithoutCatchAllReturnsTheGenericEnvelope(): void
    {
        // EventosDeTicketController has no try/catch: an outbox row with an
        // unmapped event_type makes EventoEnTiempoRealFactory throw an
        // \InvalidArgumentException. Before phase 3b, Symfony rendered it as a
        // problem+json 500 that leaked the exception message.
        $this->connection()->insert('outbox_events', [
            'id' => (string) new Ulid(),
            'aggregate_type' => 'ticket',
            'aggregate_id' => self::TICKET_ID,
            'topic' => 'tickets',
            'event_type' => 'NoSuchEvent',
            'payload' => '{}',
            'created_at' => '2026-01-01 10:00:00',
            'status' => 'pending',
            'sequence' => 1,
        ]);
        $token = $this->agentToken();
        $mercureToken = static::getContainer()->get(MercureJwtService::class)->createSubscriberToken(['tickets'])->toString();
        $this->client->getCookieJar()->set(new Cookie('mercureAuthorization', $mercureToken));

        $response = $this->request('GET', '/api/tickets/' . self::TICKET_ID . '/events', $token);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertStringNotContainsString('NoSuchEvent', (string) $response->getContent());
    }

    public function testUnknownApiRouteKeepsTheFrameworkNotFound(): void
    {
        $response = $this->request('GET', '/api/no-such-route', null);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'An error occurred',
            'status' => 404,
            'detail' => 'No route found for "GET http://localhost/api/no-such-route"',
            'class' => 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
        ], $this->problemWithoutTrace($response));
    }

    public function testUnauthenticatedApiCallKeepsTheFirewall401(): void
    {
        $response = $this->request('GET', '/api/teams', null);

        $this->assertJsonResponse(401, ['code' => 401, 'message' => 'JWT Token not found'], $response);
    }

    public function testIsGrantedDenialKeepsTheFramework403(): void
    {
        $token = $this->agentToken();

        // app_report_export is gated by #[IsGranted('ROLE_SUPERVISOR')]; access_control only asks for ROLE_AGENT.
        $response = $this->request('GET', '/api/reports/export', $token);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'An error occurred',
            'status' => 403,
            'detail' => "Access Denied. The user doesn't have ROLE_SUPERVISOR.",
            'class' => 'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException',
        ], $this->problemWithoutTrace($response));
    }
}
