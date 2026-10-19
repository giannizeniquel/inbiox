<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Agent;

use App\Agente\Agente;
use App\Administracion\User;
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
        $agent = new Agente('Support Bot');

        self::assertSame('Support Bot', $agent->getNombre());
        self::assertNull($agent->getUsuario(), 'New agent must have null user');
        self::assertTrue($agent->isActivo());
    }

    public function testAgentNoEmailProperty(): void
    {
        $agent = new Agente('Test Agent');

        // Email property no longer exists on Agent
        self::assertFalse(property_exists($agent, 'email'), 'Agent must not have an email property');
    }

    public function testSetUserLinksAgentToUser(): void
    {
        $user = new User();
        $user->setEmail('agent@inbiox.local');

        $agent = new Agente('Linked Agent');
        $agent->setUsuario($user);

        self::assertSame($user, $agent->getUsuario());
    }

    public function testUnlinkUserSetsNull(): void
    {
        $user = new User();
        $user->setEmail('agent@inbiox.local');

        $agent = new Agente('Linked Agent');
        $agent->setUsuario($user);
        self::assertNotNull($agent->getUsuario());

        $agent->setUsuario(null);
        self::assertNull($agent->getUsuario(), 'After unlink, user must be null');
    }

    public function testUserCanExistWithoutAgent(): void
    {
        $user = new User();
        $user->setEmail('admin@inbiox.local');

        self::assertNull($user->getAgent(), 'User created without Agent must have null agent');
    }

    public function testAgentRemainsFunctionalForTicketAssignment(): void
    {
        $agent = new Agente('Support Bot');
        $agent->setMaxTicketsActivos(10);
        $agent->setZonaHoraria('UTC');

        self::assertSame(10, $agent->getMaxTicketsActivos());
        self::assertSame('UTC', $agent->getZonaHoraria());
        self::assertTrue($agent->isDisponible());

        // Agent can operate without being linked to a user
        self::assertNull($agent->getUsuario());
    }

    public function testSetUserReturnsSelfForChaining(): void
    {
        $user = new User();
        $user->setEmail('agent@inbiox.local');

        $agent = new Agente('Chained Agent');
        $result = $agent->setUsuario($user);

        self::assertSame($agent, $result);
    }

    public function testUserBidirectionalRelationship(): void
    {
        $user = new User();
        $user->setEmail('agent@inbiox.local');

        $agent = new Agente('BiDi Agent');
        $agent->setUsuario($user);

        // User's inverse side maps back to the same agent
        // Note: Doctrine manages the inversedBy — at entity level we set it explicitly
        // The inverse side is set via User::setAgent
        $user->setAgent($agent);

        self::assertSame($agent, $user->getAgent());
        self::assertSame($user, $agent->getUsuario());
    }

    public function testSetTimezoneRejectsInvalidIana(): void
    {
        $agent = new Agente('Invalid Timezone Agent');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone: "Not/AZone"');

        $agent->setZonaHoraria('Not/AZone');
    }

    public function testAgentKeepsExistingFields(): void
    {
        $agent = new Agente('Existing Fields Agent');
        $agent->setActivo(false);
        $agent->setDisponible(false);
        $agent->setMaxTicketsActivos(15);
        $agent->setZonaHoraria('America/New_York');

        self::assertFalse($agent->isActivo());
        self::assertFalse($agent->isDisponible());
        self::assertSame(15, $agent->getMaxTicketsActivos());
        self::assertSame('America/New_York', $agent->getZonaHoraria());
        self::assertNotNull($agent->getCreadoAt());
        self::assertNotNull($agent->getActualizadoAt());
    }
}
