<?php

declare(strict_types=1);

namespace App\Application\Security\Voter;

use App\Domain\Ticket\Ticket;
use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes actions on Ticket objects.
 *
 * Attribute matrix:
 *   VIEW   | EDIT   | ASSIGN | CLOSE
 *   ------ | ------ | ------ | ------
 *   ADMIN       ✓       ✓       ✓       ✓
 *   SUPERVISOR  ✓       ✓       ✓       ✓
 *   AGENT       ✓(*)    ✓(*)    ✓(*)    ✓(*)
 *
 *   (*) AGENT only for tickets where ticket.assignedAgentId === user.agent.id
 *       Unassigned tickets (assignedAgentId IS NULL) are NOT visible to AGENT.
 */
final class TicketVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT = 'EDIT';
    public const string ASSIGN = 'ASSIGN';
    public const string CLOSE = 'CLOSE';

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

            /** @var Ticket $ticket */
            $ticket = $subject;

            // R2: unassigned tickets are not visible to AGENT
            if (null === $ticket->getAssignedAgentId()) {
                return false;
            }

            return $ticket->getAssignedAgentId() === $agent->getId();
        }

        return false;
    }
}
