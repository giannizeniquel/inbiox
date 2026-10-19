<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Agente\Repository\AgenteRepository;
use App\Agente\UseCase\AsegurarAgenteDeUsuarioUseCase;
use App\Compartido\Graph\GraphClient;
use App\Conversacion\UseCase\ProcesarMensajeSalienteUseCase;
use App\Conversacion\UseCase\MarcarMensajeSalienteEnviadoUseCase;
use App\Conversacion\UseCase\RegistrarPlantillaEnviadaUseCase;
use App\Conversacion\UseCase\EnviarMensajeSalienteUseCase;
use App\Conversacion\UseCase\EnviarPlantillaUseCase;
use App\Conversacion\UseCase\SubirAdjuntoUseCase;
use App\Conversacion\Controller\ProxyDeProveedorController;
use App\Conversacion\Repository\MensajeRepository;
use App\Agente\Agente;
use App\Administracion\Usuario;
use App\Compartido\Storage\MediaStorageService;
use App\Conversacion\ValidadorDeAdjunto;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `ProxyDeProveedorController::getAgenteQueActuaId()` (design.md
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
    private function makeController(EntityManagerInterface $em, AgenteRepository $agentRepository): ProxyDeProveedorController
    {
        return new ProxyDeProveedorController(
            $this->createMock(GraphClient::class),
            new EnviarMensajeSalienteUseCase(
                $this->createMock(TicketRepository::class),
                $this->createMock(GraphClient::class),
                $this->createMock(ProcesarMensajeSalienteUseCase::class),
                new MarcarMensajeSalienteEnviadoUseCase($em, $this->createMock(MensajeRepository::class)),
            ),
            new EnviarPlantillaUseCase(
                $this->createMock(TicketRepository::class),
                $this->createMock(GraphClient::class),
                new RegistrarPlantillaEnviadaUseCase($em),
            ),
            new AsegurarAgenteDeUsuarioUseCase($em, $agentRepository),
            new SubirAdjuntoUseCase(
                $this->createMock(ValidadorDeAdjunto::class),
                $this->createMock(GraphClient::class),
                $this->createMock(MediaStorageService::class),
            ),
        );
    }

    private function invokeResolveActingAgentId(ProxyDeProveedorController $controller, ?Usuario $user): ?int
    {
        $method = new \ReflectionMethod($controller, 'getAgenteQueActuaId');
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
        $user->setAgente($agent);

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
