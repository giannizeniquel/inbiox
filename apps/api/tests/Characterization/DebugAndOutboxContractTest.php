<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

/**
 * Response keys of the operational routes of contract A.13: the dev and test
 * `/debug/*` routes and `POST /admin/outbox/publish`. Their paths stay (D5
 * exceptions); only the keys change.
 */
final class DebugAndOutboxContractTest extends CharacterizationTestCase
{
    public function testDebugStatusAnswersWithTheNewKeys(): void
    {
        $ticket = $this->createTicket('activo');
        $token = $this->loginAs('debug-status@characterization.local', ['ROLE_ADMINISTRADOR']);

        $body = $this->json($this->request('POST', '/debug/status/' . $ticket->getId(), $token));

        self::assertSame(['exito', 'ticketId', 'estadoNuevo'], \array_keys($body));
        self::assertTrue($body['exito']);
        self::assertSame($ticket->getId(), $body['ticketId']);
        self::assertContains($body['estadoNuevo'], ['en_espera', 'resuelto']);
    }

    public function testDebugFailureAnswersWithExitoFalse(): void
    {
        $token = $this->loginAs('debug-fail@characterization.local', ['ROLE_ADMINISTRADOR']);

        $response = $this->request('POST', '/debug/status/999999999', $token);

        self::assertSame(500, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(['exito', 'error'], \array_keys($body));
        self::assertFalse($body['exito']);
    }

    public function testOutboxPublishAnswersWithPublicados(): void
    {
        // Sin filas pendientes el publicador no llega a Mercure.
        self::assertSame(0, $this->countRows('outbox_events'));
        $token = $this->loginAs('outbox@characterization.local', ['ROLE_ADMINISTRADOR']);

        $this->assertJsonResponse(200, ['publicados' => 0], $this->request('POST', '/admin/outbox/publish', $token));
    }
}
