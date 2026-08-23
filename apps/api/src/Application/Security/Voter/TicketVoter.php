<?php

declare(strict_types=1);

namespace App\Application\Security\Voter;

use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes actions on Ticket objects.
 *
 * IMPORTANT — read before "fixing" anything here:
 *
 * Inbiox is a SHARED inbox by design (confirmed product decision, see
 * docs/04_sprints/sprint_16 (Production Readiness).md). Any authenticated
 * agent can VIEW any ticket, regardless of who it's assigned to. There is
 * no VIEW check applied anywhere in the controllers that list or read
 * tickets — visibility is intentionally unrestricted.
 *
 * The ASSIGN attribute below is the only one actually invoked by callers
 * today, and its "AGENT can only act on own tickets" logic only applies to
 * ASSIGN (e.g. reassigning/taking over a ticket). It does NOT reflect a
 * VIEW restriction.
 *
 * The VIEW, EDIT, and CLOSE constants are defined for future use but are
 * currently never checked by any controller (reserved/dead code, kept
 * intentionally — do not remove). If they are wired up in the future,
 * revisit the logic below since it was written under the (incorrect, for
 * VIEW) assumption that unassigned/other-agent tickets should be hidden.
 *
 * Real attribute matrix (as actually enforced today):
 *   - VIEW / EDIT / CLOSE: not checked by any controller — reserved for
 *     future use, currently a no-op if ever called.
 *   - ASSIGN: the only attribute actually invoked today.
 *       ADMIN / SUPERVISOR: always allowed (subject to the resolved/closed
 *         state guard below).
 *       AGENT: only allowed when the ticket is already assigned to that
 *         agent (ticket.assignedAgentId === user.agent.id); denied for
 *         unassigned tickets and tickets assigned to a different agent.
 */
final class TicketVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const ASSIGN = 'ASSIGN';
    public const CLOSE = 'CLOSE';

    public function __construct(
        private readonly Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::ASSIGN, self::CLOSE], true)
            && $subject instanceof Ticket;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Ticket $ticket */
        $ticket = $subject;

        // For ASSIGN attribute, reject resolved/closed tickets with 409
        if ($attribute === self::ASSIGN) {
            $status = $ticket->getStatus();
            if ($status === TicketStatus::RESOLVED->value || $status === TicketStatus::CLOSED->value) {
                throw new \App\Exception\StateConflictException(
                    \sprintf('Cannot assign ticket in "%s" state', $status)
                );
            }
        }

        // ADMIN and SUPERVISOR can do everything on tickets
        if ($this->security->isGranted('ROLE_SUPERVISOR')) {
            return true;
        }

        // AGENT can only act on own tickets
        if ($this->security->isGranted('ROLE_AGENT')) {
            $agent = $user->getAgent();

            if (null === $agent) {
                return false; // R1: no linked agent → 403
            }

            // R2: AGENT cannot ASSIGN an unassigned ticket via this voter
            // (this only governs the ASSIGN attribute, not visibility —
            // ticket VIEW is unrestricted for all authenticated agents).
            if (null === $ticket->getAssignedAgentId()) {
                return false;
            }

            return $ticket->getAssignedAgentId() === $agent->getId();
        }

        return false;
    }
}
