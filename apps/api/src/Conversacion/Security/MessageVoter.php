<?php

declare(strict_types=1);

namespace App\Conversacion\Security;

use App\Conversacion\Message;
use App\Administracion\User;
use App\Ticket\Repository\TicketRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes access to Message resources.
 *
 * A caller can ACCESS a message if they can VIEW the parent ticket.
 * SUPERVISOR+ can access any message.
 * AGENT can access messages on tickets assigned to them.
 */
final class MessageVoter extends Voter
{
    public const ACCESS = 'ACCESS';

    public function __construct(
        private readonly Security $security,
        private readonly TicketRepository $ticketRepository,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCESS && $subject instanceof Message;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Message $message */
        $message = $subject;
        $conversation = $message->getConversation();

        if ($conversation === null) {
            return false;
        }

        // SUPERVISOR+ can access any message
        if ($this->security->isGranted('ROLE_SUPERVISOR')) {
            return true;
        }

        // AGENT can access messages on tickets they're assigned to
        if ($this->security->isGranted('ROLE_AGENT')) {
            $agent = $user->getAgent();

            if ($agent === null) {
                return false;
            }

            // Find the ticket for this conversation
            $ticket = $this->ticketRepository->findByConversation($conversation);

            if ($ticket === null) {
                return false;
            }

            // Agent can access if assigned to this ticket
            return $ticket->getAssignedAgentId() === $agent->getId();
        }

        return false;
    }
}
