<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Agente\Repository\AgenteRepository;
use App\Agente\UseCase\AsegurarAgenteDeUsuarioUseCase;
use App\Compartido\Graph\GraphClient;
use App\Conversacion\UseCase\HandleOutboundMessageUseCase;
use App\Conversacion\UseCase\MarkOutboundMessageSentUseCase;
use App\Conversacion\UseCase\RecordSentTemplateUseCase;
use App\Conversacion\UseCase\SendOutboundMessageUseCase;
use App\Conversacion\UseCase\SendTemplateUseCase;
use App\Conversacion\UseCase\UploadMediaUseCase;
use App\Conversacion\Controller\ProviderProxyController;
use App\Conversacion\Repository\MessageRepository;
use App\Agente\Agente;
use App\Administracion\Usuario;
use App\Compartido\Storage\MediaStorageService;
use App\Conversacion\MediaValidator;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `ProviderProxyController::resolveActingAgentId()` (design.md
 * D4) — the private helper extracted from `send()`'s original inline
 * fallback, reused by `sendTemplate()`. Since phase 3d it delegates to
 * `AsegurarAgenteDeUsuarioUseCase`, built here over the mocked EntityManager.
 *
 * spec.md "Attribution at Template Send":
 *   - Scenario: Agent sends a template message → resolved agent id, AGENT
 *   - Scenario: Current user has no Agent record → send MUST NOT fail;
 *     sender_agent_id is null while sender_type remains agent
 */
class ProviderProxyControllerResolveAgentTest extends TestCase
{
    private function makeController(EntityManagerInterface $em, AgenteRepository $agentRepository): ProviderProxyController
    {
        return new ProviderProxyController(
            $this->createMock(GraphClient::class),
            new SendOutboundMessageUseCase(
                $this->createMock(TicketRepository::class),
                $this->createMock(GraphClient::class),
                $this->createMock(HandleOutboundMessageUseCase::class),
                new MarkOutboundMessageSentUseCase($em, $this->createMock(MessageRepository::class)),
            ),
            new SendTemplateUseCase(
                $this->createMock(TicketRepository::class),
                $this->createMock(GraphClient::class),
                new RecordSentTemplateUseCase($em),
            ),
            new AsegurarAgenteDeUsuarioUseCase($em, $agentRepository),
            new UploadMediaUseCase(
                $this->createMock(MediaValidator::class),
                $this->createMock(GraphClient::class),
                $this->createMock(MediaStorageService::class),
            ),
        );
    }

    private function invokeResolveActingAgentId(ProviderProxyController $controller, ?Usuario $user): ?int
    {
        $method = new \ReflectionMethod($controller, 'resolveActingAgentId');
        $method->setAccessible(true);

        return $method->invoke($controller, $user);
    }

    public function testReturnsNullWhenUserIsNull(): void
    {
        $agentRepository = $this->createMock(AgenteRepository::class);
        $agentRepository->expects(self::never())->method('findOneBy');

        $controller = $this->makeController($this->createMock(EntityManagerInterface::class), $agentRepository);

        self::assertNull($this->invokeResolveActingAgentId($controller, null));
    }

    public function testReturnsExistingAgentIdWhenUserAlreadyHasAnAgentRecord(): void
    {
        $user = new Usuario();
        $user->setEmail('has-agent@inbiox.local');

        $agent = new Agente('Existing Agent');
        $reflectedId = new \ReflectionProperty($agent, 'id');
        $reflectedId->setAccessible(true);
        $reflectedId->setValue($agent, 42);
        $agent->setUsuario($user);
        $user->setAgent($agent);

        $agentRepository = $this->createMock(AgenteRepository::class);
        $agentRepository->expects(self::never())->method('findOneBy');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $controller = $this->makeController($em, $agentRepository);

        self::assertSame(42, $this->invokeResolveActingAgentId($controller, $user));
    }

    public function testDegradesToNullWithoutThrowingWhenAgentResolutionFails(): void
    {
        // spec.md "Current user has no Agent record" — simulate the
        // degrade path by making persist() throw during Agent auto-creation
        // (e.g. a transient DB error). The send must not fail: null is
        // returned instead of propagating the exception.
        $user = new Usuario();
        $user->setEmail('no-agent@inbiox.local');

        $agentRepository = $this->createMock(AgenteRepository::class);
        $agentRepository->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willThrowException(new \RuntimeException('DB unavailable'));

        $controller = $this->makeController($em, $agentRepository);

        self::assertNull($this->invokeResolveActingAgentId($controller, $user));
    }
}
