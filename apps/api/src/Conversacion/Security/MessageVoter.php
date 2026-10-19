<?php

declare(strict_types=1);

namespace App\Conversacion\Security;

use App\Conversacion\Mensaje;
use App\Administracion\Usuario;
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
        return $attribute === self::ACCESS && $subject instanceof Mensaje;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Usuario) {
            return false;
        }

        /** @var Mensaje $message */
        $message = $subject;
        $conversation = $message->getConversacion();

        if ($conversation === null) {
            return false;
        }

        // SUPERVISOR+ can access any message
        if ($this->security->isGranted('ROLE_SUPERVISOR')) {
            return true;
        }

        // AGENT can access messages on tickets they're assigned to
        if ($this->security->isGranted('ROLE_AGENT')) {
            $agent = $user->getAgente();

            if ($agent === null) {
                return false;
            }

            // Find the ticket for this conversation
            $ticket = $this->ticketRepository->findByConversacion($conversation);

            if ($ticket === null) {
                return false;
            }

            // Agent can access if assigned to this ticket
            return $ticket->getAgenteAsignadoId() === $agent->getId();
        }

        return false;
    }
}
