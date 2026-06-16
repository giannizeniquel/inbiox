<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\User;

use App\Domain\User\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit tests for the User entity.
 *
 * Covers identity-management spec scenarios 1–3:
 *   1. Create user with valid data
 *   2. Duplicate email rejected (DB-level — verified via unique constraint)
 *   3. User without Agent (ADMIN/SUPERVISOR)
 */
class UserTest extends TestCase
{
    public function testCreateUserWithValidData(): void
    {
        $user = new User();
        $user->setEmail('admin@inbiox.local');
        $user->setPassword('hashed_password_here');
        $user->setRoles(['ROLE_ADMIN']);

        self::assertSame('admin@inbiox.local', $user->getEmail());
        self::assertSame('admin@inbiox.local', $user->getUserIdentifier());
        self::assertSame('hashed_password_here', $user->getPassword());
        self::assertTrue($user->isActive(), 'is_active must default to true');
        self::assertNotNull($user->getCreatedAt(), 'created_at must be auto-set');
    }

    public function testUserImplementsRequiredInterfaces(): void
    {
        $user = new User();

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertInstanceOf(PasswordAuthenticatedUserInterface::class, $user);
    }

    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $user = new User();
        $user->setRoles([]);

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles, 'ROLE_USER must always be present');
    }

    public function testGetRolesMergesWithRoleUser(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN', 'ROLE_AGENT']);

        $roles = $user->getRoles();

        self::assertContains('ROLE_ADMIN', $roles);
        self::assertContains('ROLE_AGENT', $roles);
        self::assertContains('ROLE_USER', $roles);
        self::assertCount(3, $roles, 'Must have exactly ROLE_ADMIN, ROLE_AGENT, ROLE_USER');
    }

    public function testGetRolesIsUnique(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER']); // already contains ROLE_USER

        $roles = $user->getRoles();

        self::assertCount(1, $roles, 'ROLE_USER must not be duplicated');
        self::assertSame(['ROLE_USER'], \array_values($roles));
    }

    public function testEraseCredentialsIsNoop(): void
    {
        $user = new User();
        $user->setPassword('some_hash');

        $user->eraseCredentials();

        // Password remains unchanged (no plain-text secrets stored)
        self::assertSame('some_hash', $user->getPassword());
    }

    public function testIsActiveCanBeSet(): void
    {
        $user = new User();
        self::assertTrue($user->isActive());

        $user->setIsActive(false);
        self::assertFalse($user->isActive());

        $user->setIsActive(true);
        self::assertTrue($user->isActive());
    }

    public function testSetRolesUpdatesRawRoles(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        $raw = $user->getRawRoles();
        self::assertSame(['ROLE_ADMIN'], $raw);
    }

    public function testUserWithoutAgent(): void
    {
        $user = new User();
        self::assertNull($user->getAgent(), 'User created without Agent must have null agent');
    }

    public function testGetIdBeforePersistIsNull(): void
    {
        $user = new User();
        self::assertNull($user->getId());
    }

    public function testUpdatedAtIsInitiallyNull(): void
    {
        $user = new User();
        self::assertNull($user->getUpdatedAt());
    }

    public function testSetIsActiveTouchesUpdatedAt(): void
    {
        $user = new User();
        $before = $user->getUpdatedAt();

        // Small delay to ensure timestamp changes
        \usleep(1000);
        $user->setIsActive(false);

        $after = $user->getUpdatedAt();
        self::assertNotNull($after, 'updatedAt must be set after modification');
        self::assertGreaterThan($before ?? $user->getCreatedAt(), $after);
    }
}
