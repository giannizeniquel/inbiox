<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 3b: App\Compartido\EventListener\InternalServerErrorListener turns any
 * uncaught non-HTTP exception on an /api route into the generic 500 envelope
 * (shape S1 in odd/fase-3/errores.md), and leaves HTTP and security errors alone.
 */
final class InternalServerErrorListenerTest extends CharacterizationTestCase
{
    private function agentToken(): string
    {
        return $this->loginAs('listener@characterization.local', ['ROLE_AGENTE'], $this->createAgent('Agent'));
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
        // AsignacionDeTicketController::asignar catches only its domain
        // exceptions. Framework validation is disabled, so a body without the
        // required `asignadoPor` reaches the action with an uninitialized typed
        // property and fails with an \Error. Before phase 3b, Symfony rendered
        // such a failure as a problem+json 500 that leaked the exception message.
        $ticket = $this->createTicket('nuevo');
        $token = $this->loginAs('listener-admin@characterization.local', ['ROLE_ADMINISTRADOR']);

        $response = $this->request('POST', '/api/tickets/' . $ticket->getId() . '/asignar', $token, ['agenteId' => 1]);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertStringNotContainsString('asignadoPor', (string) $response->getContent());
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
        $response = $this->request('GET', '/api/equipos', null);

        $this->assertJsonResponse(401, ['code' => 401, 'message' => 'JWT Token not found'], $response);
    }

    public function testIsGrantedDenialKeepsTheFramework403(): void
    {
        $token = $this->agentToken();

        // app_report_export is gated by #[IsGranted('ROLE_SUPERVISOR')]; access_control only asks for ROLE_AGENTE.
        $response = $this->request('GET', '/api/reportes/exportar', $token);

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
