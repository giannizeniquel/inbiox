<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Application\Service\GraphClient;
use App\Application\UseCase\HandleOutboundMessageUseCase;
use App\Controller\ProviderProxyController;
use App\Domain\Agent\Agent;
use App\Domain\User\User;
use App\Infrastructure\Storage\MediaStorageService;
use App\Infrastructure\Storage\MediaValidator;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `ProviderProxyController::resolveActingAgentId()` (design.md
 * D4) — the private helper extracted from `send()`'s original inline
 * fallback, reused by `sendTemplate()`.
 *
 * spec.md "Attribution at Template Send":
 *   - Scenario: Agent sends a template message → resolved agent id, AGENT
 *   - Scenario: Current user has no Agent record → send MUST NOT fail;
 *     sender_agent_id is null while sender_type remains agent
 */
class ProviderProxyControllerResolveAgentTest extends TestCase
{
    private function makeController(EntityManagerInterface $em): ProviderProxyController
    {
        return new ProviderProxyController(
            $this->createMock(TicketRepository::class),
            $this->createMock(GraphClient::class),
            $em,
            $this->createMock(HandleOutboundMessageUseCase::class),
            $this->createMock(MediaValidator::class),
            $this->createMock(MediaStorageService::class),
        );
    }

    private function invokeResolveActingAgentId(ProviderProxyController $controller, ?User $user): ?int
    {
        $method = new \ReflectionMethod($controller, 'resolveActingAgentId');
        $method->setAccessible(true);

        return $method->invoke($controller, $user);
    }

    public function testReturnsNullWhenUserIsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        $controller = $this->makeController($em);

        self::assertNull($this->invokeResolveActingAgentId($controller, null));
    }

    public function testReturnsExistingAgentIdWhenUserAlreadyHasAnAgentRecord(): void
    {
        $user = new User();
        $user->setEmail('has-agent@inbiox.local');

        $agent = new Agent('Existing Agent');
        $reflectedId = new \ReflectionProperty($agent, 'id');
        $reflectedId->setAccessible(true);
        $reflectedId->setValue($agent, 42);
        $agent->setUser($user);
        $user->setAgent($agent);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $em->expects(self::never())->method('persist');

        $controller = $this->makeController($em);

        self::assertSame(42, $this->invokeResolveActingAgentId($controller, $user));
    }

    public function testDegradesToNullWithoutThrowingWhenAgentResolutionFails(): void
    {
        // spec.md "Current user has no Agent record" — simulate the
        // degrade path by making persist() throw during Agent auto-creation
        // (e.g. a transient DB error). The send must not fail: null is
        // returned instead of propagating the exception.
        $user = new User();
        $user->setEmail('no-agent@inbiox.local');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willThrowException(new \RuntimeException('DB unavailable'));

        $controller = $this->makeController($em);

        self::assertNull($this->invokeResolveActingAgentId($controller, $user));
    }
}
