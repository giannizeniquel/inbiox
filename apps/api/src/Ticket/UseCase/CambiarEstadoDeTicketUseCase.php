<?php

namespace App\Ticket\UseCase;

use App\Asignacion\AssignmentService;
use App\Agente\Agent;
use App\Asignacion\AssignmentType;
use App\Ticket\EventoDeTicket;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\MaquinaDeEstadosDeTicket;
use App\Ticket\EstadoDeTicket;
use App\Agente\Repository\AgentRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transitions ticket status following FSM rules.
 * Sets SLA timestamps deterministically.
 *
 * On resolve/close, if the ticket has an owner, creates an UNASSIGN
 * Assignment audit row via AssignmentService to close the audit gap (W1).
 */
class CambiarEstadoDeTicketUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private MaquinaDeEstadosDeTicket $maquinaDeEstados,
        private AssignmentService $assignmentService,
        private AgentRepository $agentRepository,
    ) {}

    /**
     * Record UNASSIGN audit if the ticket owner is being resolved/closed.
     */
    private function registrarDesasignacionSiEsResponsable(int $ticketId, string $actor = 'system'): void
    {
        $ticketEntity = $this->ticketRepo->findById($ticketId);
        if ($ticketEntity === null) {
            return;
        }

        $responsableId = $ticketEntity->getAgenteAsignadoId();
        if ($responsableId === null) {
            return;
        }

        $agente = $this->agentRepository->findById($responsableId);
        if ($agente === null) {
            return;
        }

        $this->assignmentService->recordOwnershipChange(
            ticket: $ticketEntity,
            agent: null,
            previousAgent: $agente,
            type: AssignmentType::UNASSIGN,
            assignedBy: $actor,
        );
    }

    /**
     * Transition ticket to new status with FSM validation
     *
     * @param int $ticketId
     * @param string $estadoDestino
     * @param bool $setPrimeraRespuesta - whether to set first_response_at (agent reply)
     * @param bool $setResuelto - whether to set resolved_at
     * @return array
     */
    public function execute(
        int $ticketId,
        string $estadoDestino,
        bool $setPrimeraRespuesta = false,
        bool $setResuelto = false,
        string $actor = 'system'
    ): array {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $estadoDestino, $setPrimeraRespuesta, $setResuelto, $actor) {
            // Lock ticket for update
            $ticket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            $estadoActual = $ticket['status'];

            // Validate transition via FSM
            $nuevoEstado = $this->maquinaDeEstados->transicionar($estadoActual, $estadoDestino);

            // Record UNASSIGN audit for resolve/close transitions
            if (($nuevoEstado === EstadoDeTicket::RESUELTO->value || $nuevoEstado === EstadoDeTicket::CERRADO->value)
                && !empty($ticket['assigned_agent_id'])
            ) {
                $this->registrarDesasignacionSiEsResponsable($ticketId, $actor);
                // Flush entity changes (Assignment + ticket entity modifications)
                // so they are part of this transaction before the raw SQL status update
                $this->em->flush();
            }

            // Set first_response_at if this is an agent reply and not already set
            $marcarPrimeraRespuesta = $setPrimeraRespuesta && empty($ticket['first_response_at']);

            // Set resolved_at if transitioning to resolved
            $marcarResuelto = $setResuelto && $nuevoEstado === EstadoDeTicket::RESUELTO->value && empty($ticket['resolved_at']);

            // Set closed_at when landing on closed via this generic entry
            // point (design.md D7) — the agent's primary own-ticket close
            // path once /close is structurally unowned-only (D3).
            $marcarCerrado = $nuevoEstado === EstadoDeTicket::CERRADO->value && empty($ticket['closed_at']);

            $this->ticketRepo->updateEstadoTrasTransicion(
                $ticketId,
                $nuevoEstado,
                $marcarPrimeraRespuesta,
                $marcarResuelto,
                $marcarCerrado,
            );

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $estadoActual,
                'new_status' => $nuevoEstado,
                'previous_assigned_agent_id' => isset($ticket['assigned_agent_id'])
                    ? (int) $ticket['assigned_agent_id'] : null,
                'reason' => 'success',
            ];
        });
    }

    /**
     * Close a ticket because its WhatsApp 24h conversation window has
     * elapsed (design.md D2/D3).
     *
     * Unlike `close()`, this does not require the ticket to be `resolved` —
     * validity is delegated entirely to the widened FSM
     * (`new|active|waiting|resolved → closed`, task 0.9). Writes a
     * `EventoDeTicket(WINDOW_EXPIRED_CLOSED, 'system')` row so the closure is
     * distinguishable from a human close in the audit trail (ticket-closure-
     * audit capability).
     */
    public function cerrarPorVentanaVencida(int $ticketId): array
    {
        return $this->em->getConnection()->transactional(function () use ($ticketId) {
            $ticket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            $estadoActual = $ticket['status'];

            // Validity delegated to the FSM (task 0.9's widening); rejects
            // an already-closed ticket via its own guard — no special-cased
            // check needed here.
            $nuevoEstado = $this->maquinaDeEstados->transicionar($estadoActual, EstadoDeTicket::CERRADO->value);

            if (!empty($ticket['assigned_agent_id'])) {
                $this->registrarDesasignacionSiEsResponsable($ticketId);
                $this->em->flush();
            }

            $this->ticketRepo->marcarCerrado($ticketId, $nuevoEstado);

            $evento = new EventoDeTicket($ticketId, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO, 'system');
            $this->em->persist($evento);
            $this->em->flush();

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $estadoActual,
                'new_status' => $nuevoEstado,
                'reason' => 'window_expired',
            ];
        });
    }

    /**
     * Reopen a ticket that was closed by the WhatsApp 24h window-expiry
     * process, in response to a new inbound customer message (design.md D1).
     *
     * This method deliberately NEVER calls `$this->maquinaDeEstados` — no
     * `closed → *` entry exists in `MaquinaDeEstadosDeTicket::TRANSITIONS`, and
     * none is ever added, so this reopen path is structurally unreachable
     * from any human-facing endpoint (e.g. `POST /tickets/{id}/transition`).
     * It has its own independent guard, mirroring how `closeDueToWindowExpiry()`
     * and `close()` already coexist without colliding.
     *
     * Guard 2 (latest event type) is re-checked here, inside the locked
     * transaction, rather than trusted from the repository lookup that fed
     * the caller: the two calls are separated in time and a concurrent
     * close could change the reason in between.
     */
    public function reabrirPorMensajeEntrante(int $ticketId): array
    {
        return $this->em->getConnection()->transactional(function () use ($ticketId) {
            $ticket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            // Guard 1: only a closed ticket is eligible.
            if ($ticket['status'] !== EstadoDeTicket::CERRADO->value) {
                throw new \InvalidArgumentException('Only closed tickets can be reopened: ' . $ticketId);
            }

            // Guard 2: the ticket's latest event must be a window-expiry
            // closure — re-checked here under the lock, not trusted from
            // the caller's earlier repository lookup.
            $ultimoTipo = $this->ticketRepo->findUltimoTipoDeEvento($ticketId);
            if ($ultimoTipo !== TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO) {
                throw new \InvalidArgumentException(
                    'Ticket ' . $ticketId . ' is not eligible for system-triggered reopen'
                );
            }

            $nuevoEstado = EstadoDeTicket::ACTIVO->value;

            $this->ticketRepo->marcarReabierto($ticketId, $nuevoEstado);

            $evento = new EventoDeTicket($ticketId, TipoDeEventoDeTicket::VENTANA_REABIERTO, 'system');
            $this->em->persist($evento);
            $this->em->flush();

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $ticket['status'],
                'new_status' => $nuevoEstado,
                'reason' => 'window_reopened',
            ];
        });
    }

    /**
     * Resolve a ticket (manual or automated)
     */
    public function resolver(int $ticketId, string $actor = 'system'): array
    {
        return $this->execute($ticketId, EstadoDeTicket::RESUELTO->value, true, true, $actor);
    }

    /**
     * Close a resolved ticket
     */
    public function cerrar(int $ticketId, string $actor = 'system'): array
    {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $actor) {
            $ticket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            if ($ticket['status'] !== EstadoDeTicket::RESUELTO->value) {
                throw new \InvalidArgumentException('Only resolved tickets can be closed');
            }

            // Record UNASSIGN audit if ticket has an owner
            if (!empty($ticket['assigned_agent_id'])) {
                $this->registrarDesasignacionSiEsResponsable($ticketId, $actor);
                $this->em->flush();
            }

            $this->ticketRepo->marcarCerrado($ticketId, EstadoDeTicket::CERRADO->value);

            return [
                'ticket_id' => $ticketId,
                'previous_status' => $ticket['status'],
                'new_status' => EstadoDeTicket::CERRADO->value,
                'previous_assigned_agent_id' => isset($ticket['assigned_agent_id'])
                    ? (int) $ticket['assigned_agent_id'] : null,
                'reason' => 'success',
            ];
        });
    }
}