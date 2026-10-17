<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Ticket\Security\TicketVoter;
use App\Agente\Agent;
use App\Ticket\Ticket;
use App\Administracion\User;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Unit tests for authorization voters (PR3).
 *
 * Covers the RBAC matrix for TicketVoter.
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
     * @param string $attribute The voter attribute (ASSIGN, CLOSE)
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
        $ticket->method('getAgenteAsignadoId')->willReturn($ticketAgentId);

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
            'ADMIN ASSIGN other ticket'    => ['ROLE_ADMIN', 2, 1, TicketVoter::ASSIGN, $g],
            'ADMIN CLOSE other ticket'     => ['ROLE_ADMIN', 2, 1, TicketVoter::CLOSE, $g],

            // SUPERVISOR — all permissions on any ticket
            'SUPERVISOR ASSIGN other ticket'    => ['ROLE_SUPERVISOR', 2, 1, TicketVoter::ASSIGN, $g],
            'SUPERVISOR CLOSE other ticket'     => ['ROLE_SUPERVISOR', 2, 1, TicketVoter::CLOSE, $g],

            // AGENT — own assigned ticket
            'AGENT ASSIGN own ticket'           => ['ROLE_AGENT', 1, 1, TicketVoter::ASSIGN, $g],
            'AGENT CLOSE own ticket'            => ['ROLE_AGENT', 1, 1, TicketVoter::CLOSE, $g],

            // AGENT — other agent's ticket (denied)
            'AGENT ASSIGN other ticket'         => ['ROLE_AGENT', 2, 1, TicketVoter::ASSIGN, $d],
            'AGENT CLOSE other ticket'          => ['ROLE_AGENT', 2, 1, TicketVoter::CLOSE, $d],

            // AGENT — unassigned ticket (denied — R2)
            'AGENT ASSIGN unassigned ticket'    => ['ROLE_AGENT', null, 1, TicketVoter::ASSIGN, $d],

            // AGENT — no linked agent (denied — R1)
            'AGENT no agent ASSIGN'             => ['ROLE_AGENT', 1, null, TicketVoter::ASSIGN, $d],

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
        $ticket->method('getEstado')->willReturn(\App\Ticket\EstadoDeTicket::RESUELTO->value);
        $ticket->method('getAgenteAsignadoId')->willReturn(null);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $voter = new TicketVoter($security);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $ticket, [TicketVoter::CLOSE]),
            'CLOSE on a resolved ticket must be granted for a supervisor, no exception'
        );

        $this->expectException(\App\Compartido\StateConflictException::class);
        $voter->vote($token, $ticket, [TicketVoter::ASSIGN]);
    }
}
