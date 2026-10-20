<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Ticket\TransicionInvalidaException;
use App\Ticket\UseCase\ResolverTicketUseCase;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;

/**
 * Characterization of the 500 envelope of App\Ticket\Controller\FlujoDeTicketController.
 * It was shape S2 in odd/fase-3/errores.md until phase 3b, which unified it into S1.
 * The other paths of this controller already have HTTP tests.
 */
final class TicketWorkflowControllerCharacterizationTest extends CharacterizationTestCase
{
    // An id with no ticket row, so the ownership voter is skipped and the use case runs.
    private const MISSING_TICKET_ID = 999999999;

    private function assertInternalErrorEnvelope(\Symfony\Component\HttpFoundation\Response $response): void
    {
        // Phase 3b: S2 unified into S1.
        $this->assertInternalServerErrorEnvelope($response);
        self::assertStringNotContainsString('workflow exploded', (string) $response->getContent());
    }

    private function supervisorToken(): string
    {
        return $this->loginAs('workflow@characterization.local', ['ROLE_SUPERVISOR'], $this->createAgent('Sup'));
    }

    public function testTransitionUnexpectedFailure(): void
    {
        $token = $this->supervisorToken();
        $useCase = $this->createMock(CambiarEstadoDeTicketUseCase::class);
        $useCase->method('execute')->willThrowException(new \RuntimeException('workflow exploded'));
        static::getContainer()->set(CambiarEstadoDeTicketUseCase::class, $useCase);

        $response = $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/transicionar', $token, ['estado' => 'activo']);

        $this->assertInternalErrorEnvelope($response);
    }

    public function testCloseUnexpectedFailure(): void
    {
        $token = $this->supervisorToken();
        $useCase = $this->createMock(CambiarEstadoDeTicketUseCase::class);
        $useCase->method('cerrar')->willThrowException(new \RuntimeException('workflow exploded'));
        static::getContainer()->set(CambiarEstadoDeTicketUseCase::class, $useCase);

        $response = $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/cerrar', $token);

        $this->assertInternalErrorEnvelope($response);
    }

    public function testResolveUnexpectedFailure(): void
    {
        $token = $this->supervisorToken();
        $useCase = $this->createMock(ResolverTicketUseCase::class);
        $useCase->method('execute')->willThrowException(new \RuntimeException('workflow exploded'));
        static::getContainer()->set(ResolverTicketUseCase::class, $useCase);

        $response = $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/resolver', $token);

        $this->assertInternalErrorEnvelope($response);
    }

    public function testInvalidTransitionIs422OnTransitionButGeneric500OnResolve(): void
    {
        $token = $this->supervisorToken();
        $transition = $this->createMock(CambiarEstadoDeTicketUseCase::class);
        $transition->method('execute')->willThrowException(new TransicionInvalidaException('Cannot go there'));
        static::getContainer()->set(CambiarEstadoDeTicketUseCase::class, $transition);
        $resolve = $this->createMock(ResolverTicketUseCase::class);
        $resolve->method('execute')->willThrowException(new TransicionInvalidaException('Cannot go there'));
        static::getContainer()->set(ResolverTicketUseCase::class, $resolve);

        $this->assertJsonResponse(422, ['error' => 'Cannot go there'], $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/transicionar', $token, ['estado' => 'activo']));

        // Characterization: current behavior, not necessarily desired.
        // resolve() has no TransicionInvalidaException arm, so it falls into the 500.
        $response = $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/resolver', $token);
        // Phase 3b: S2 unified into S1.
        $this->assertInternalServerErrorEnvelope($response);
    }
}
