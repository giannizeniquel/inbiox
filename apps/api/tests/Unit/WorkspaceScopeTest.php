<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Compartido\Security\WorkspaceScope;
use App\Agente\Agente;
use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Unit tests for WorkspaceScope.
 *
 * Covers:
 * - ROLE_AGENT with active workspace → returns phoneNumberId
 * - ROLE_SUPERVISOR → null (no scope)
 * - ROLE_ADMIN → null (no scope)
 * - ROLE_AGENT without active workspace → null
 * - No authenticated user → null
 */
class WorkspaceScopeTest extends TestCase
{
    public function testAgentWithActiveWorkspaceReturnsPhoneNumberId(): void
    {
        $workspace = $this->createMock(EspacioDeTrabajo::class);
        $workspace->method('getPhoneNumberId')->willReturn('1146240195247464');

        $agent = $this->createMock(Agente::class);
        $agent->method('getEspacioDeTrabajoActivo')->willReturn($workspace);

        $user = new Usuario();
        $user->setRoles(['ROLE_AGENT']);
        $user->setAgente($agent);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(function (string $role): bool {
            return \in_array($role, ['ROLE_AGENT'], true);
        });

        $scope = new WorkspaceScope($security);
        self::assertSame('1146240195247464', $scope->getActivePhoneNumberId());
    }

    public function testSupervisorReturnsNull(): void
    {
        $user = new Usuario();
        $user->setRoles(['ROLE_SUPERVISOR']);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(function (string $role): bool {
            return 'ROLE_SUPERVISOR' === $role || 'ROLE_AGENT' === $role;
        });

        $scope = new WorkspaceScope($security);
        self::assertNull($scope->getActivePhoneNumberId());
    }

    public function testAdminReturnsNull(): void
    {
        $user = new Usuario();
        $user->setRoles(['ROLE_ADMIN']);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(function (string $role): bool {
            return \in_array($role, ['ROLE_ADMIN', 'ROLE_SUPERVISOR', 'ROLE_AGENT'], true);
        });

        $scope = new WorkspaceScope($security);
        self::assertNull($scope->getActivePhoneNumberId());
    }

    public function testAgentWithoutActiveWorkspaceReturnsNull(): void
    {
        $agent = $this->createMock(Agente::class);
        $agent->method('getEspacioDeTrabajoActivo')->willReturn(null);

        $user = new Usuario();
        $user->setRoles(['ROLE_AGENT']);
        $user->setAgente($agent);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(function (string $role): bool {
            return \in_array($role, ['ROLE_AGENT'], true);
        });

        $scope = new WorkspaceScope($security);
        self::assertNull($scope->getActivePhoneNumberId());
    }

    public function testUnauthenticatedReturnsNull(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $scope = new WorkspaceScope($security);
        self::assertNull($scope->getActivePhoneNumberId());
    }
}
