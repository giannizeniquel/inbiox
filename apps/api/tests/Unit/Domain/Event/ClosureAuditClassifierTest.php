<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Ticket\ClasificadorDeAuditoriaDeCierre;
use App\Ticket\TipoDeEventoDeTicket;
use App\Administracion\Usuario;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `ClasificadorDeAuditoriaDeCierre` (design.md D2).
 *
 * Pure, dependency-free classifier: no Doctrine, no HTTP.
 */
class ClosureAuditClassifierTest extends TestCase
{
    private ClasificadorDeAuditoriaDeCierre $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ClasificadorDeAuditoriaDeCierre();
    }

    /**
     * @dataProvider classifyProvider
     */
    public function testClassify(string $newStatus, ?int $previousOwnerId, ?int $actingAgentId, string $expected): void
    {
        self::assertSame(
            $expected,
            $this->classifier->clasificar($newStatus, $previousOwnerId, $actingAgentId)
        );
    }

    /**
     * @return array<string, array{string, ?int, ?int, string}>
     */
    public static function classifyProvider(): array
    {
        return [
            // resolved — unowned: (null, any) → UNOWNED
            'resolved unowned, acting agent set'    => ['resolved', null, 5, TipoDeEventoDeTicket::MANUAL_RESUELTO_SIN_RESPONSABLE],
            'resolved unowned, no acting agent'     => ['resolved', null, null, TipoDeEventoDeTicket::MANUAL_RESUELTO_SIN_RESPONSABLE],
            // resolved — owner: (7, 7) → OWNER
            'resolved owner'                        => ['resolved', 7, 7, TipoDeEventoDeTicket::MANUAL_RESUELTO_RESPONSABLE],
            // resolved — override: (7, 3|null) → OVERRIDE
            'resolved override, different agent'    => ['resolved', 7, 3, TipoDeEventoDeTicket::MANUAL_RESUELTO_FORZADO],
            'resolved override, no acting agent'    => ['resolved', 7, null, TipoDeEventoDeTicket::MANUAL_RESUELTO_FORZADO],

            // closed — unowned: (null, any) → UNOWNED
            'closed unowned, acting agent set'      => ['closed', null, 5, TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE],
            'closed unowned, no acting agent'       => ['closed', null, null, TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE],
            // closed — owner: (7, 7) → OWNER
            'closed owner'                          => ['closed', 7, 7, TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE],
            // closed — override: (7, 3|null) → OVERRIDE
            'closed override, different agent'      => ['closed', 7, 3, TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO],
            'closed override, no acting agent'      => ['closed', 7, null, TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO],
        ];
    }

    public function testActorReferenceReturnsNamespacedUserId(): void
    {
        $user = new Usuario();
        $prop = new \ReflectionProperty($user, 'id');
        $prop->setAccessible(true);
        $prop->setValue($user, 9);

        self::assertSame('u:9', $this->classifier->referenciaDeActor($user));
    }

    /**
     * Guards the `ticket_events.type` VARCHAR(50) contract (design.md D2).
     */
    public function testEveryConstantFitsTheColumnWidth(): void
    {
        $constants = [
            TipoDeEventoDeTicket::MANUAL_RESUELTO_RESPONSABLE,
            TipoDeEventoDeTicket::MANUAL_RESUELTO_FORZADO,
            TipoDeEventoDeTicket::MANUAL_RESUELTO_SIN_RESPONSABLE,
            TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE,
            TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO,
            TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE,
        ];

        foreach ($constants as $constant) {
            self::assertLessThanOrEqual(50, \strlen($constant), \sprintf('Constant "%s" exceeds VARCHAR(50)', $constant));
        }
    }
}
