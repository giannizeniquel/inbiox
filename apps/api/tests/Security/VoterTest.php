<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Application\Security\Voter\AgentVoter;
use App\Application\Security\Voter\ReportVoter;
use App\Application\Security\Voter\TicketVoter;
use App\Domain\Agent\Agent;
use App\Domain\Ticket\Ticket;
use App\Domain\User\User;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Unit tests for authorization voters (PR3).
 *
 * Covers the full RBAC matrix for TicketVoter, AgentVoter, and ReportVoter.
 */
class VoterTest extends TestCase
{
    // ────────────────────────────────────────────────────────
    //  TicketVoter tests
    // ────────────────────────────────────────────────────────

    /**
     * @param string $role ROLE_ADMIN, ROLE_SUPERVISOR, or ROLE_AGENT
     * @param int|null $ticketAgentId The assigned agent of the ticket (null = unassigned)
     * @param int|null $userAgentId The agent linked to the user (null = no agent)
     * @param string $attribute The voter attribute (VIEW, EDIT, ASSIGN, CLOSE)
     * @param int $expected VoterInterface::ACCESS_GRANTED or ACCESS_DENIED
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ticketVoterProvider')]
    public function testTicketVoter(
        string $role,
        ?int $ticketAgentId,
        ?int $userAgentId,
        string $attribute,
        int $expected,
    ): void {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(function (string $roleName) use ($role): bool {
            if ('ROLE_ADMIN' === $roleName) {
                return 'ROLE_ADMIN' === $role;
            }
            if ('ROLE_SUPERVISOR' === $roleName) {
                return \in_array($role, ['ROLE_ADMIN', 'ROLE_SUPERVISOR'], true);
            }
            if ('ROLE_AGENT' === $roleName) {
                return true; // All roles have ROLE_AGENT via hierarchy
            }
            return false;
        });

        $user = new User();
        $user->setRoles([$role]);

        if (null !== $userAgentId) {
            $agent = $this->createMock(Agent::class);
            $agent->method('getId')->willReturn($userAgentId);
            $user->setAgent($agent);
        }

        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getAssignedAgentId')->willReturn($ticketAgentId);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $voter = new TicketVoter($security);
        $result = $voter->vote($token, $ticket, [$attribute]);

        self::assertSame($expected, $result, sprintf(
            'TicketVoter: role=%s, ticketAgentId=%s, userAgentId=%s, attribute=%s',
            $role,
            $ticketAgentId ?? 'null',
            $userAgentId ?? 'null',
            $attribute,
        ));
    }

    /**
     * @return array<string, array{string, ?int, ?int, string, int}>
     */
    public static function ticketVoterProvider(): array
    {
        $g = VoterInterface::ACCESS_GRANTED;
        $d = VoterInterface::ACCESS_DENIED;

        return [
            // ADMIN — all permissions on any ticket
            'ADMIN VIEW own ticket'        => ['ROLE_ADMIN', 1, 1, TicketVoter::VIEW, $g],
            'ADMIN VIEW other ticket'      => ['ROLE_ADMIN', 2, 1, TicketVoter::VIEW, $g],
            'ADMIN VIEW unassigned ticket' => ['ROLE_ADMIN', null, 1, TicketVoter::VIEW, $g],
            'ADMIN EDIT other ticket'      => ['ROLE_ADMIN', 2, 1, TicketVoter::EDIT, $g],
            'ADMIN ASSIGN other ticket'    => ['ROLE_ADMIN', 2, 1, TicketVoter::ASSIGN, $g],
            'ADMIN CLOSE other ticket'     => ['ROLE_ADMIN', 2, 1, TicketVoter::CLOSE, $g],

            // SUPERVISOR — all permissions on any ticket
            'SUPERVISOR VIEW other ticket'      => ['ROLE_SUPERVISOR', 2, 1, TicketVoter::VIEW, $g],
            'SUPERVISOR EDIT other ticket'      => ['ROLE_SUPERVISOR', 2, 1, TicketVoter::EDIT, $g],
            'SUPERVISOR ASSIGN other ticket'    => ['ROLE_SUPERVISOR', 2, 1, TicketVoter::ASSIGN, $g],
            'SUPERVISOR CLOSE other ticket'     => ['ROLE_SUPERVISOR', 2, 1, TicketVoter::CLOSE, $g],
            'SUPERVISOR VIEW unassigned'        => ['ROLE_SUPERVISOR', null, 1, TicketVoter::VIEW, $g],

            // AGENT — own assigned ticket
            'AGENT VIEW own ticket'             => ['ROLE_AGENT', 1, 1, TicketVoter::VIEW, $g],
            'AGENT EDIT own ticket'             => ['ROLE_AGENT', 1, 1, TicketVoter::EDIT, $g],
            'AGENT ASSIGN own ticket'           => ['ROLE_AGENT', 1, 1, TicketVoter::ASSIGN, $g],
            'AGENT CLOSE own ticket'            => ['ROLE_AGENT', 1, 1, TicketVoter::CLOSE, $g],

            // AGENT — other agent's ticket (denied)
            'AGENT VIEW other ticket'           => ['ROLE_AGENT', 2, 1, TicketVoter::VIEW, $d],
            'AGENT EDIT other ticket'           => ['ROLE_AGENT', 2, 1, TicketVoter::EDIT, $d],

            // AGENT — unassigned ticket (denied — R2)
            'AGENT VIEW unassigned ticket'      => ['ROLE_AGENT', null, 1, TicketVoter::VIEW, $d],

            // AGENT — no linked agent (denied — R1)
            'AGENT no agent VIEW'               => ['ROLE_AGENT', 1, null, TicketVoter::VIEW, $d],
            'AGENT no agent EDIT'               => ['ROLE_AGENT', 1, null, TicketVoter::EDIT, $d],

            // AGENT — CLOSE on an unassigned ticket (denied — R2). This is
            // the design.md D3 case: every ticket reachable via POST
            // /tickets/{id}/close is structurally unowned, so this must
            // deny even the agent who resolved it seconds earlier.
            'AGENT CLOSE unassigned ticket'     => ['ROLE_AGENT', null, 1, TicketVoter::CLOSE, $d],

            // AGENT — no linked Agent, CLOSE own ticket (denied — R1)
            'AGENT no agent CLOSE own ticket'   => ['ROLE_AGENT', 1, null, TicketVoter::CLOSE, $d],
        ];
    }

    /**
     * Regression proof for design.md D1: wiring `TicketVoter::CLOSE` must
     * NOT collide with the `ASSIGN` attribute's resolved/closed state guard.
     * The identical (SUPERVISOR, resolved ticket) case is GRANTED with no
     * exception under CLOSE, but still throws `StateConflictException`
     * under ASSIGN.
     */
    public function testSupervisorCloseGrantedOnResolvedTicketWhileAssignStillThrows(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            fn (string $roleName): bool => \in_array($roleName, ['ROLE_SUPERVISOR', 'ROLE_AGENT'], true)
        );

        $user = new User();
        $user->setRoles(['ROLE_SUPERVISOR']);

        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getStatus')->willReturn(\App\Domain\Ticket\TicketStatus::RESOLVED->value);
        $ticket->method('getAssignedAgentId')->willReturn(null);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $voter = new TicketVoter($security);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $ticket, [TicketVoter::CLOSE]),
            'CLOSE on a resolved ticket must be granted for a supervisor, no exception'
        );

        $this->expectException(\App\Exception\StateConflictException::class);
        $voter->vote($token, $ticket, [TicketVoter::ASSIGN]);
    }

    // ────────────────────────────────────────────────────────
    //  AgentVoter tests
    // ────────────────────────────────────────────────────────

    /**
     * @param string|null $role ROLE_ADMIN, ROLE_SUPERVISOR, ROLE_AGENT, or null for unauthenticated
     * @param int|null $targetAgentId The agent being acted on
     * @param int|null $userAgentId The agent linked to the user
     * @param string $attribute The voter attribute
     * @param int $expected ACCESS_GRANTED or ACCESS_DENIED
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('agentVoterProvider')]
    public function testAgentVoter(
        ?string $role,
        ?int $targetAgentId,
        ?int $userAgentId,
        string $attribute,
        int $expected,
    ): void {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(function (string $roleName) use ($role): bool {
            if (null === $role) {
                return false;
            }
            if ('ROLE_ADMIN' === $roleName) {
                return 'ROLE_ADMIN' === $role;
            }
            if ('ROLE_SUPERVISOR' === $roleName) {
                return \in_array($role, ['ROLE_ADMIN', 'ROLE_SUPERVISOR'], true);
            }
            if ('ROLE_AGENT' === $roleName) {
                return null !== $role;
            }
            return false;
        });

        $token = $this->createMock(TokenInterface::class);

        if (null === $role) {
            // Unauthenticated user
            $token->method('getUser')->willReturn(null);
        } else {
            $user = new User();
            $user->setRoles([$role]);

            if (null !== $userAgentId) {
                $agent = $this->createMock(Agent::class);
                $agent->method('getId')->willReturn($userAgentId);
                $user->setAgent($agent);
            }

            $token->method('getUser')->willReturn($user);
        }

        $targetAgent = $this->createMock(Agent::class);
        $targetAgent->method('getId')->willReturn($targetAgentId);

        $voter = new AgentVoter($security);
        $result = $voter->vote($token, $targetAgent, [$attribute]);

        self::assertSame($expected, $result, sprintf(
            'AgentVoter: role=%s, targetAgentId=%s, userAgentId=%s, attribute=%s',
            $role ?? 'null',
            $targetAgentId ?? 'null',
            $userAgentId ?? 'null',
            $attribute,
        ));
    }

    /**
     * @return array<string, array{?string, ?int, ?int, string, int}>
     */
    public static function agentVoterProvider(): array
    {
        $g = VoterInterface::ACCESS_GRANTED;
        $d = VoterInterface::ACCESS_DENIED;

        return [
            // ADMIN — all attributes, all agents
            'ADMIN VIEW_AGENT any'              => ['ROLE_ADMIN', 1, null, AgentVoter::VIEW_AGENT, $g],
            'ADMIN VIEW_AVAILABILITY any'       => ['ROLE_ADMIN', 1, null, AgentVoter::VIEW_AVAILABILITY, $g],
            'ADMIN EDIT_AGENT any'              => ['ROLE_ADMIN', 1, null, AgentVoter::EDIT_AGENT, $g],

            // SUPERVISOR — VIEW and EDIT any agent
            'SUPERVISOR VIEW_AGENT any'         => ['ROLE_SUPERVISOR', 1, null, AgentVoter::VIEW_AGENT, $g],
            'SUPERVISOR EDIT_AGENT any'         => ['ROLE_SUPERVISOR', 1, null, AgentVoter::EDIT_AGENT, $g],
            'SUPERVISOR VIEW_AVAILABILITY any'  => ['ROLE_SUPERVISOR', 1, null, AgentVoter::VIEW_AVAILABILITY, $g],

            // AGENT — VIEW_AGENT own agent
            'AGENT VIEW_AGENT own'              => ['ROLE_AGENT', 5, 5, AgentVoter::VIEW_AGENT, $g],
            'AGENT VIEW_AVAILABILITY own'       => ['ROLE_AGENT', 5, 5, AgentVoter::VIEW_AVAILABILITY, $g],

            // AGENT — denied for other's agent
            'AGENT VIEW_AGENT other'            => ['ROLE_AGENT', 5, 3, AgentVoter::VIEW_AGENT, $d],
            'AGENT VIEW_AVAILABILITY other'     => ['ROLE_AGENT', 5, 3, AgentVoter::VIEW_AVAILABILITY, $d],

            // AGENT — cannot EDIT
            'AGENT EDIT_AGENT own'              => ['ROLE_AGENT', 5, 5, AgentVoter::EDIT_AGENT, $d],
            'AGENT EDIT_AGENT other'            => ['ROLE_AGENT', 5, 3, AgentVoter::EDIT_AGENT, $d],

            // AGENT — no linked agent
            'AGENT no agent VIEW_AGENT'         => ['ROLE_AGENT', 5, null, AgentVoter::VIEW_AGENT, $d],

            // Unauthenticated
            'NO_USER VIEW_AGENT'                => [null, 5, null, AgentVoter::VIEW_AGENT, $d],
        ];
    }

    // ────────────────────────────────────────────────────────
    //  ReportVoter tests
    // ────────────────────────────────────────────────────────

    /**
     * @param string $role ROLE_ADMIN, ROLE_SUPERVISOR, or ROLE_AGENT
     * @param int $expected ACCESS_GRANTED or ACCESS_DENIED
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reportVoterProvider')]
    public function testReportVoter(string $role, int $expected): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(function (string $roleName) use ($role): bool {
            if ('ROLE_SUPERVISOR' === $roleName) {
                return \in_array($role, ['ROLE_ADMIN', 'ROLE_SUPERVISOR'], true);
            }
            if ('ROLE_AGENT' === $roleName) {
                return true;
            }
            return false;
        });

        $user = new User();
        $user->setRoles([$role]);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $voter = new ReportVoter($security);
        $result = $voter->vote($token, null, [ReportVoter::VIEW_REPORT]);

        self::assertSame($expected, $result, sprintf(
            'ReportVoter: role=%s, expected=%s',
            $role,
            $expected === VoterInterface::ACCESS_GRANTED ? 'GRANTED' : 'DENIED',
        ));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function reportVoterProvider(): array
    {
        $g = VoterInterface::ACCESS_GRANTED;
        $d = VoterInterface::ACCESS_DENIED;

        return [
            'ADMIN view reports'       => ['ROLE_ADMIN', $g],
            'SUPERVISOR view reports'  => ['ROLE_SUPERVISOR', $g],
            'AGENT view reports'       => ['ROLE_AGENT', $d],
        ];
    }
}
