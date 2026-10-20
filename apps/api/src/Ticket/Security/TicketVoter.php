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
 * Autoriza acciones sobre objetos Ticket.
 *
 * IMPORTANTE — leer antes de "arreglar" cualquier cosa aquí:
 *
 * Inbiox es una bandeja COMPARTIDA por diseño (decisión de producto confirmada, ver
 * docs/04_sprints/sprint_16 (Production Readiness).md). Cualquier agente
 * autenticado puede VER cualquier ticket, sin importar a quién esté asignado. No se
 * aplica ningún chequeo VIEW en los controllers que listan o leen
 * tickets — la visibilidad es intencionalmente irrestricta.
 *
 * Los atributos ASSIGN y CLOSE son los que realmente invocan quienes llaman
 * hoy. Su lógica de "AGENT solo puede actuar sobre sus propios tickets" se aplica a ambos
 * (p. ej. reasignar/tomar un ticket, o resolverlo/cerrarlo). NO
 * refleja una restricción de VIEW.
 *
 * Deliberadamente no existe un atributo VIEW/EDIT: la visibilidad es irrestricta.
 * Si alguna vez se agrega uno, no reutilizar la lógica de "solo tickets propios" de abajo.
 *
 * Matriz de atributos:
 *   - ASSIGN: lo invoca AsignacionDeTicketController::asignar(). Rechaza con un 409
 *     `ConflictoDeEstadoException` cuando el ticket ya está resolved/closed,
 *     para todos los roles, antes incluso de que se ejecuten los chequeos de rol de abajo.
 *   - CLOSE: lo invoca FlujoDeTicketController para resolve()/close()/
 *     transition() (destino resolved/closed). NO pasa por la guarda de estado
 *     de ASSIGN — un ticket resuelto es justamente el estado al que apunta un cierre
 *     legítimo (ticket-closure-authorization design.md D1/D3).
 *   ASSIGN y CLOSE comparten la misma lógica de roles una vez superada la guarda
 *   de estado de ASSIGN:
 *       ADMIN / SUPERVISOR: siempre permitido.
 *       AGENT: permitido solo cuando el ticket ya está asignado a ese
 *         agente (ticket.assignedAgentId === user.agent.id); denegado para
 *         tickets sin asignar y tickets asignados a otro agente.
 *         Nota (D3): todo ticket que llega a `POST /tickets/{id}/close`
 *         está estructuralmente sin asignar para ese momento (resolver
 *         desasigna), así que CLOSE deniega a todo ROLE_AGENTE en ese endpoint —
 *         solo supervisores/admins pueden usarlo. El cierre de un ticket propio
 *         por parte de un agente pasa por `POST /transition {"status":"closed"}`,
 *         mientras el ticket todavía tiene responsable.
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

        // Para el atributo ASSIGN, rechaza los tickets resolved/closed con 409
        if ($attribute === self::ASSIGN) {
            $estado = $ticket->getEstado();
            if ($estado === EstadoDeTicket::RESUELTO->value || $estado === EstadoDeTicket::CERRADO->value) {
                throw new \App\Compartido\ConflictoDeEstadoException(
                    \sprintf('Cannot assign ticket in "%s" state', $estado)
                );
            }
        }

        // ADMIN y SUPERVISOR pueden hacer todo sobre los tickets
        if ($this->security->isGranted('ROLE_SUPERVISOR')) {
            return true;
        }

        // AGENT solo puede actuar sobre sus propios tickets
        if ($this->security->isGranted('ROLE_AGENTE')) {
            $agente = $usuario->getAgente();

            if (null === $agente) {
                return false; // R1: sin agente vinculado → 403
            }

            // R2: AGENT no puede hacer ASSIGN de un ticket sin asignar mediante este voter
            // (esto solo rige el atributo ASSIGN, no la visibilidad —
            // el VIEW de tickets es irrestricto para todos los agentes autenticados).
            if (null === $ticket->getAgenteAsignadoId()) {
                return false;
            }

            return $ticket->getAgenteAsignadoId() === $agente->getId();
        }

        return false;
    }
}
