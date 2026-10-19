<?php

declare(strict_types=1);

namespace App\Asignacion\UseCase;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Asignacion\ResponsabilidadDeTicketService;
use App\Asignacion\TipoDeAsignacion;
use App\Compartido\EventCollector;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketUpdatedEvent;
use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Ticket\EstadoDeTicket;
use App\Disponibilidad\AgenteInactivoException;
use App\Agente\Repository\AgentRepository;
use App\Asignacion\Repository\AsignacionRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/**
 * Manual ticket assignment by a supervisor.
 *
 * Routes through ResponsabilidadDeTicketService for the audit trail — the ONLY path
 * that modifies ticket.assigned_agent_id.
 *
 * Assignment types detected:
 *   - MANUAL_ASSIGN: ticket had no owner → initial assignment
 *   - REASSIGN:      ticket already had an owner → transfer
 */
class AsignarTicketUseCase
{
    /** Track the last assignment type for outbox payload enrichment. */
    private ?string $ultimoTipoDeAsignacion = null;

    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private AsignacionRepository $asignacionRepo,
        private AgentRepository $agentRepo,
        private EventCollector $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private ResponsabilidadDeTicketService $responsabilidadDeTicketService,
        private DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
    ) {}

    /**
     * Assign a ticket to an agent (manual assignment).
     *
     * @param int $ticketId
     * @param int $agenteId
     * @param string $asignadoPor
     * @param string|null $notas
     * @return array
     * @throws \InvalidArgumentException if ticket or agent not found
     */
    public function asignar(int $ticketId, int $agenteId, string $asignadoPor, ?string $notas = null): array
    {
        // Validate input
        if ($agenteId <= 0) {
            throw new \InvalidArgumentException('Agent ID is required');
        }

        if ($asignadoPor === '') {
            throw new \InvalidArgumentException('Assigned by is required');
        }

        $this->ultimoTipoDeAsignacion = null;

        $result = $this->em->getConnection()->transactional(function () use ($ticketId, $agenteId, $asignadoPor, $notas) {
            // Find the agent
            $agente = $this->agentRepo->findById($agenteId);
            if (!$agente) {
                throw new \InvalidArgumentException('Agent not found');
            }

            // Find the ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
            }

            // Lock ticket to prevent concurrent modifications
            $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);

            // Assign-time Activo guard: HARD BLOCK for every role, no bypass.
            // Capacity is never checked here — only account-active + override/shift.
            $isActivoAhora = $this->disponibilidadDeAgenteService->isActivoAhora(
                $agente,
                $agente->getShifts()->toArray(),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            if (!$isActivoAhora) {
                throw AgenteInactivoException::paraAgente($agenteId, $agente->getName());
            }

            // Detect assignment type from current ownership
            $responsableActualId = $ticket->getAgenteAsignadoId();
            $agenteAnterior = null;

            if ($responsableActualId !== null && $responsableActualId !== $agenteId) {
                // REASSIGN — ticket has a different owner
                $agenteAnterior = $this->agentRepo->findById($responsableActualId);
            }

            $tipo = $responsableActualId !== null ? TipoDeAsignacion::REASIGNACION : TipoDeAsignacion::MANUAL;
            $this->ultimoTipoDeAsignacion = $tipo->value;

            // Route through ResponsabilidadDeTicketService (creates audit row, updates ticket, emits events)
            $asignacion = $this->responsabilidadDeTicketService->registrarCambioDeResponsable(
                ticket: $ticket,
                agente: $agente,
                agenteAnterior: $agenteAnterior,
                tipo: $tipo,
                asignadoPor: $asignadoPor,
                notas: $notas,
            );

            $this->em->flush();

            return [
                'assignment_id' => $asignacion->getId(),
                'ticket_id' => $ticket->getId(),
                'agent_id' => $asignacion->getAgenteId(),
                'agent_name' => $agente->getName(),
                'assigned_by' => $asignacion->getAsignadoPor(),
                'assigned_at' => $asignacion->getAsignadoAt()->format('Y-m-d H:i:s'),
                'ticket_status' => $ticket->getEstado(),
                'notes' => $asignacion->getNotas(),
                'status' => 'ok',
            ];
        });

        // After commit, persist collected events to outbox
        $this->persistEventsToOutbox();

        return $result;
    }

    /**
     * Persist collected domain events to outbox table.
     */
    private function persistEventsToOutbox(): void
    {
        $events = $this->eventCollector->getEvents();
        if (empty($events)) {
            return;
        }

        $outboxEvents = [];

        foreach ($events as $event) {
            $payload = [
                'eventId' => $event->eventId->toRfc4122(),
                'type' => $event->type,
                'ticketId' => $event->ticketId,
                'occurredAt' => $event->occurredAt->format(\DateTimeInterface::RFC3339),
                'seq' => $event->seq,
                'version' => $event->version,
            ];

            // Add event-specific fields
            if ($event instanceof TicketUpdatedEvent) {
                $payload['status'] = $event->status?->value;
                $payload['ownerAgentId'] = $event->ownerAgentId;
                $payload['assignee_id'] = $event->ownerAgentId;
                $payload['assignment_type'] = $this->ultimoTipoDeAsignacion;
            }

            $outboxEvent = new OutboxEvent(
                aggregateType: 'ticket',
                aggregateId: $event->ticketId,
                topic: 'tickets',
                eventType: $event->type,
                payload: $payload,
            );
            $outboxEvents[] = $outboxEvent;
        }

        $this->outboxWriter->write(...$outboxEvents);
        $this->eventCollector->clear();
    }
}
