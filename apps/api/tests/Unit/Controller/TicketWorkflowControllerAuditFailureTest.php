<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Ticket\UseCase\ResolveTicketUseCase;
use App\Ticket\UseCase\TransitionTicketStatusUseCase;
use App\Ticket\Controller\TicketWorkflowController;
use App\Agente\Agent;
use App\Ticket\ClosureAuditClassifier;
use App\Ticket\Ticket;
use App\Ticket\TicketStatus;
use App\Administracion\User;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * design.md D8, tasks.md 4.4/4.5: an audit-write failure must not fail an
 * already-committed transition. Unit-level (mocked collaborators) because
 * the failure mode is a simulated `EntityManagerInterface::flush()` throw,
 * which is impractical to trigger deterministically through a real
 * integration test.
 */
class TicketWorkflowControllerAuditFailureTest extends TestCase
{
    private function buildController(EntityManagerInterface $em, LoggerInterface $logger): array
    {
        $agent = $this->createMock(Agent::class);
        $agent->method('getId')->willReturn(7);

        $user = new User();
        $reflection = new \ReflectionProperty($user, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, 9);
        $user->setRoles(['ROLE_SUPERVISOR']);
        $user->setAgent($agent);

        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getAssignedAgentId')->willReturn(7);
        $ticket->method('getId')->willReturn(42);

        $ticketRepo = $this->createMock(TicketRepository::class);
        $ticketRepo->method('findById')->willReturn($ticket);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);

        $transitionUseCase = $this->createMock(TransitionTicketStatusUseCase::class);
        $transitionUseCase->method('close')->willReturn([
            'ticket_id' => 42,
            'previous_status' => TicketStatus::RESOLVED->value,
            'new_status' => TicketStatus::CLOSED->value,
            'previous_assigned_agent_id' => 7,
            'reason' => 'success',
        ]);

        $resolveUseCase = $this->createMock(ResolveTicketUseCase::class);

        $controller = new TicketWorkflowController(
            $transitionUseCase,
            $resolveUseCase,
            $logger,
            $ticketRepo,
            $security,
            $em,
            new ClosureAuditClassifier(),
        );

        return [$controller, $user];
    }

    public function testAuditWriteFailureStillReturnsTheSuccessfulTransitionResponse(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('DB unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('closure audit'),
                self::callback(function (array $context): bool {
                    return $context['ticket_id'] === 42
                        && \is_string($context['type'])
                        && \is_string($context['source']);
                })
            );

        [$controller, $user] = $this->buildController($em, $logger);

        $response = $controller->close(42, $user);

        self::assertSame(200, $response->getStatusCode());
        $data = \json_decode($response->getContent(), true);
        self::assertSame('success', $data['reason']);
        self::assertArrayNotHasKey('previous_assigned_agent_id', $data);
    }
}
