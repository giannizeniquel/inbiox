<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Compartido\Security\AlcanceDeResponsabilidad;
use App\Administracion\Usuario;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Unit tests for AlcanceDeResponsabilidad.
 *
 * Covers:
 * - ROLE_SUPERVISOR → null (no scope)
 * - ROLE_ADMIN → null (no scope)
 * - ROLE_AGENT without linked Agent → null (R1)
 * - No authenticated user → null
 */
class OwnershipScopeTest extends TestCase
{
    public function testSupervisorReturnsNull(): void
    {
        $user = new Usuario();
        $user->setRoles(['ROLE_SUPERVISOR']);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(function (string $role): bool {
            // ROLE_SUPERVISOR is granted to ROLE_SUPERVISOR itself
            return 'ROLE_SUPERVISOR' === $role || 'ROLE_AGENT' === $role;
        });

        $scope = new AlcanceDeResponsabilidad($security);
        self::assertNull($scope->getAgentId());
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

        $scope = new AlcanceDeResponsabilidad($security);
        self::assertNull($scope->getAgentId());
    }

    public function testAgentWithoutLinkedAgentReturnsNull(): void
    {
        $user = new Usuario();
        $user->setRoles(['ROLE_AGENT']);
        // No agent set (null)

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(function (string $role): bool {
            return \in_array($role, ['ROLE_AGENT'], true);
        });

        $scope = new AlcanceDeResponsabilidad($security);
        self::assertNull($scope->getAgentId());
    }

    public function testUnauthenticatedReturnsNull(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $scope = new AlcanceDeResponsabilidad($security);
        self::assertNull($scope->getAgentId());
    }
}
