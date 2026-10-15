<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Ticket\InvalidTransitionException;
use App\Ticket\UseCase\ResolveTicketUseCase;
use App\Ticket\UseCase\TransitionTicketStatusUseCase;

/**
 * Characterization of the 500 envelope of App\Ticket\Controller\TicketWorkflowController.
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
        $useCase = $this->createMock(TransitionTicketStatusUseCase::class);
        $useCase->method('execute')->willThrowException(new \RuntimeException('workflow exploded'));
        static::getContainer()->set(TransitionTicketStatusUseCase::class, $useCase);

        $response = $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/transition', $token, ['status' => 'active']);

        $this->assertInternalErrorEnvelope($response);
    }

    public function testCloseUnexpectedFailure(): void
    {
        $token = $this->supervisorToken();
        $useCase = $this->createMock(TransitionTicketStatusUseCase::class);
        $useCase->method('close')->willThrowException(new \RuntimeException('workflow exploded'));
        static::getContainer()->set(TransitionTicketStatusUseCase::class, $useCase);

        $response = $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/close', $token);

        $this->assertInternalErrorEnvelope($response);
    }

    public function testResolveUnexpectedFailure(): void
    {
        $token = $this->supervisorToken();
        $useCase = $this->createMock(ResolveTicketUseCase::class);
        $useCase->method('execute')->willThrowException(new \RuntimeException('workflow exploded'));
        static::getContainer()->set(ResolveTicketUseCase::class, $useCase);

        $response = $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/resolve', $token);

        $this->assertInternalErrorEnvelope($response);
    }

    public function testInvalidTransitionIs422OnTransitionButGeneric500OnResolve(): void
    {
        $token = $this->supervisorToken();
        $transition = $this->createMock(TransitionTicketStatusUseCase::class);
        $transition->method('execute')->willThrowException(new InvalidTransitionException('Cannot go there'));
        static::getContainer()->set(TransitionTicketStatusUseCase::class, $transition);
        $resolve = $this->createMock(ResolveTicketUseCase::class);
        $resolve->method('execute')->willThrowException(new InvalidTransitionException('Cannot go there'));
        static::getContainer()->set(ResolveTicketUseCase::class, $resolve);

        $this->assertJsonResponse(422, ['error' => 'Cannot go there'], $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/transition', $token, ['status' => 'active']));

        // Characterization: current behavior, not necessarily desired.
        // resolve() has no InvalidTransitionException arm, so it falls into the 500.
        $response = $this->request('POST', '/api/tickets/' . self::MISSING_TICKET_ID . '/resolve', $token);
        // Phase 3b: S2 unified into S1.
        $this->assertInternalServerErrorEnvelope($response);
    }
}
