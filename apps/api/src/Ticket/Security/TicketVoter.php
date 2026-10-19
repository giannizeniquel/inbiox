<?php

declare(strict_types=1);

namespace App\Ticket\Security;

use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Usuario;
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
 * The ASSIGN and CLOSE attributes are the ones actually invoked by callers
 * today. Their "AGENT can only act on own tickets" logic applies to both
 * (e.g. reassigning/taking over a ticket, or resolving/closing it). It does
 * NOT reflect a VIEW restriction.
 *
 * There is deliberately no VIEW/EDIT attribute: visibility is unrestricted.
 * If one is ever added, do not reuse the "own tickets only" logic below.
 *
 * Attribute matrix:
 *   - ASSIGN: invoked by AsignacionDeTicketController::asignar(). Rejects with a 409
 *     `ConflictoDeEstadoException` when the ticket is already resolved/closed,
 *     for every role, before the role checks below even run.
 *   - CLOSE: invoked by FlujoDeTicketController for resolve()/close()/
 *     transition() (target resolved/closed). Does NOT hit the ASSIGN state
 *     guard — a resolved ticket is exactly the state a legitimate close
 *     targets (ticket-closure-authorization design.md D1/D3).
 *   Both ASSIGN and CLOSE share the same role logic once past ASSIGN's
 *   state guard:
 *       ADMIN / SUPERVISOR: always allowed.
 *       AGENT: only allowed when the ticket is already assigned to that
 *         agent (ticket.assignedAgentId === user.agent.id); denied for
 *         unassigned tickets and tickets assigned to a different agent.
 *         Note (D3): every ticket reachable via `POST /tickets/{id}/close`
 *         is structurally unassigned by the time it gets there (resolving
 *         unassigns), so CLOSE denies every ROLE_AGENT on that endpoint —
 *         only supervisors/admins can use it. An agent's own-ticket close
 *         goes through `POST /transition {"status":"closed"}` instead,
 *         while the ticket is still owned.
 */
final class TicketVoter extends Voter
{
    public const ASSIGN = 'ASSIGN';
    public const CLOSE = 'CLOSE';

    public function __construct(
        private readonly Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::ASSIGN, self::CLOSE], true)
            && $subject instanceof Ticket;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $usuario = $token->getUser();

        if (!$usuario instanceof Usuario) {
            return false;
        }

        /** @var Ticket $ticket */
        $ticket = $subject;

        // For ASSIGN attribute, reject resolved/closed tickets with 409
        if ($attribute === self::ASSIGN) {
            $estado = $ticket->getEstado();
            if ($estado === EstadoDeTicket::RESUELTO->value || $estado === EstadoDeTicket::CERRADO->value) {
                throw new \App\Compartido\ConflictoDeEstadoException(
                    \sprintf('Cannot assign ticket in "%s" state', $estado)
                );
            }
        }

        // ADMIN and SUPERVISOR can do everything on tickets
        if ($this->security->isGranted('ROLE_SUPERVISOR')) {
            return true;
        }

        // AGENT can only act on own tickets
        if ($this->security->isGranted('ROLE_AGENT')) {
            $agente = $usuario->getAgente();

            if (null === $agente) {
                return false; // R1: no linked agent → 403
            }

            // R2: AGENT cannot ASSIGN an unassigned ticket via this voter
            // (this only governs the ASSIGN attribute, not visibility —
            // ticket VIEW is unrestricted for all authenticated agents).
            if (null === $ticket->getAgenteAsignadoId()) {
                return false;
            }

            return $ticket->getAgenteAsignadoId() === $agente->getId();
        }

        return false;
    }
}
