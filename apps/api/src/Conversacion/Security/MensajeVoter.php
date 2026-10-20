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
 * Autoriza el acceso a los mensajes.
 *
 * Un usuario tiene ACCESS sobre un mensaje si tiene VIEW sobre su ticket.
 * SUPERVISOR+ puede acceder a cualquier mensaje.
 * AGENT puede acceder a los mensajes de los tickets que tiene asignados.
 */
final class MensajeVoter extends Voter
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
        $usuario = $token->getUser();

        if (!$usuario instanceof Usuario) {
            return false;
        }

        /** @var Mensaje $mensaje */
        $mensaje = $subject;
        $conversacion = $mensaje->getConversacion();

        if ($conversacion === null) {
            return false;
        }

        // SUPERVISOR+ puede acceder a cualquier mensaje
        if ($this->security->isGranted('ROLE_SUPERVISOR')) {
            return true;
        }

        // AGENT puede acceder a los mensajes de los tickets que tiene asignados
        if ($this->security->isGranted('ROLE_AGENTE')) {
            $agente = $usuario->getAgente();

            if ($agente === null) {
                return false;
            }

            // Busca el ticket de esta conversación
            $ticket = $this->ticketRepository->findByConversacion($conversacion);

            if ($ticket === null) {
                return false;
            }

            // El agente puede acceder si este ticket está asignado a él
            return $ticket->getAgenteAsignadoId() === $agente->getId();
        }

        return false;
    }
}
