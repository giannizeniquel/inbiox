<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Agent;

use App\Domain\Agent\Agent;
use App\Domain\User\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Agent entity — Sprint 14 changes.
 *
 * Covers identity-management spec scenarios 4–5:
 *   4. Agent created without user link
 *   5. Agent email column removed
 */
class AgentTest extends TestCase
{
    public function testCreateAgentWithoutUserLink(): void
    {
        $agent = new Agent('Support Bot');

        self::assertSame('Support Bot', $agent->getName());
        self::assertNull($agent->getUser(), 'New agent must have null user');
        self::assertTrue($agent->isActive());
    }

    public function testAgentNoEmailProperty(): void
    {
        $agent = new Agent('Test Agent');

        // Email property no longer exists on Agent
        self::assertFalse(property_exists($agent, 'email'), 'Agent must not have an email property');
    }

    public function testSetUserLinksAgentToUser(): void
    {
        $user = new User();
        $user->setEmail('agent@inbiox.local');

        $agent = new Agent('Linked Agent');
        $agent->setUser($user);

        self::assertSame($user, $agent->getUser());
    }

    public function testUnlinkUserSetsNull(): void
    {
        $user = new User();
        $user->setEmail('agent@inbiox.local');

        $agent = new Agent('Linked Agent');
        $agent->setUser($user);
        self::assertNotNull($agent->getUser());

        $agent->setUser(null);
        self::assertNull($agent->getUser(), 'After unlink, user must be null');
    }

    public function testUserCanExistWithoutAgent(): void
    {
        $user = new User();
        $user->setEmail('admin@inbiox.local');

        self::assertNull($user->getAgent(), 'User created without Agent must have null agent');
    }

    public function testAgentRemainsFunctionalForTicketAssignment(): void
    {
        $agent = new Agent('Support Bot');
        $agent->setMaxActiveTickets(10);
        $agent->setTimezone('UTC');

        self::assertSame(10, $agent->getMaxActiveTickets());
        self::assertSame('UTC', $agent->getTimezone());
        self::assertTrue($agent->isAvailable());

        // Agent can operate without being linked to a user
        self::assertNull($agent->getUser());
    }

    public function testSetUserReturnsSelfForChaining(): void
    {
        $user = new User();
        $user->setEmail('agent@inbiox.local');

        $agent = new Agent('Chained Agent');
        $result = $agent->setUser($user);

        self::assertSame($agent, $result);
    }

    public function testUserBidirectionalRelationship(): void
    {
        $user = new User();
        $user->setEmail('agent@inbiox.local');

        $agent = new Agent('BiDi Agent');
        $agent->setUser($user);

        // User's inverse side maps back to the same agent
        // Note: Doctrine manages the inversedBy — at entity level we set it explicitly
        // The inverse side is set via User::setAgent
        $user->setAgent($agent);

        self::assertSame($agent, $user->getAgent());
        self::assertSame($user, $agent->getUser());
    }

    public function testAgentKeepsExistingFields(): void
    {
        $agent = new Agent('Existing Fields Agent');
        $agent->setActive(false);
        $agent->setAvailable(false);
        $agent->setMaxActiveTickets(15);
        $agent->setTimezone('America/New_York');

        self::assertFalse($agent->isActive());
        self::assertFalse($agent->isAvailable());
        self::assertSame(15, $agent->getMaxActiveTickets());
        self::assertSame('America/New_York', $agent->getTimezone());
        self::assertNotNull($agent->getCreatedAt());
        self::assertNotNull($agent->getUpdatedAt());
    }
}
