<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Team;

use App\Domain\Agent\Agent;
use App\Domain\Team\Team;
use App\Domain\Workspace\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Team entity (Tier 2 #7 — Team/Area Entity, Slice 1).
 *
 * Covers team-management spec scenarios:
 *   - Team created within a workspace — persists active, scoped.
 *   - Agent already in team's workspace joins — success.
 *   - Agent outside team's workspace — 400 (InvalidArgumentException), no membership row created.
 */
class TeamTest extends TestCase
{
    public function testTeamCreatedWithinWorkspaceIsActiveAndScoped(): void
    {
        $workspace = new Workspace('+1000000000', 'Workspace A');
        $team = new Team($workspace, 'Support');

        self::assertTrue($team->isActive(), 'New team must default to active');
        self::assertSame($workspace, $team->getWorkspace());
        self::assertSame('Support', $team->getName());
        self::assertCount(0, $team->getAgents());
    }

    public function testAddAgentAlreadyInTeamWorkspaceSucceeds(): void
    {
        $workspace = new Workspace('+1000000000', 'Workspace A');
        $agent = new Agent('Agent A');
        $agent->addWorkspace($workspace);

        $team = new Team($workspace, 'Support');
        $team->addAgent($agent);

        self::assertTrue($team->getAgents()->contains($agent));
        self::assertTrue($agent->getTeams()->contains($team), 'Agent inverse side must be updated');
    }

    public function testAddAgentOutsideTeamWorkspaceThrows(): void
    {
        $workspaceA = new Workspace('+1000000000', 'Workspace A');
        $workspaceB = new Workspace('+2000000000', 'Workspace B');

        $agent = new Agent('Agent B');
        $agent->addWorkspace($workspaceB); // agent belongs to B, not A

        $team = new Team($workspaceA, 'Support');

        $this->expectException(\InvalidArgumentException::class);
        $team->addAgent($agent);
    }

    public function testAddAgentOutsideTeamWorkspaceCreatesNoMembership(): void
    {
        $workspaceA = new Workspace('+1000000000', 'Workspace A');
        $workspaceB = new Workspace('+2000000000', 'Workspace B');

        $agent = new Agent('Agent B');
        $agent->addWorkspace($workspaceB);

        $team = new Team($workspaceA, 'Support');

        try {
            $team->addAgent($agent);
        } catch (\InvalidArgumentException) {
            // expected
        }

        self::assertFalse($team->getAgents()->contains($agent), 'No membership row must be created on rejection');
        self::assertFalse($agent->getTeams()->contains($team), 'Agent inverse side must not be updated on rejection');
    }

    public function testRemoveAgentUpdatesBothSides(): void
    {
        $workspace = new Workspace('+1000000000', 'Workspace A');
        $agent = new Agent('Agent A');
        $agent->addWorkspace($workspace);

        $team = new Team($workspace, 'Support');
        $team->addAgent($agent);
        $team->removeAgent($agent);

        self::assertFalse($team->getAgents()->contains($agent));
        self::assertFalse($agent->getTeams()->contains($team));
    }

    public function testSetActiveTogglesFlag(): void
    {
        $workspace = new Workspace('+1000000000', 'Workspace A');
        $team = new Team($workspace, 'Support');

        $team->setActive(false);
        self::assertFalse($team->isActive());
    }
}
