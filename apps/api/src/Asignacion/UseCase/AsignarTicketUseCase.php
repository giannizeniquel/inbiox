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
        private AsignacionRepository $assignmentRepo,
        private AgentRepository $agentRepo,
        private EventCollector $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private ResponsabilidadDeTicketService $assignmentService,
        private DisponibilidadDeAgenteService $agentAvailabilityService,
    ) {}

    /**
     * Assign a ticket to an agent (manual assignment).
     *
     * @param int $ticketId
     * @param int $agentId
     * @param string $assignedBy
     * @param string|null $notes
     * @return array
     * @throws \InvalidArgumentException if ticket or agent not found
     */
    public function asignar(int $ticketId, int $agentId, string $assignedBy, ?string $notes = null): array
    {
        // Validate input
        if ($agentId <= 0) {
            throw new \InvalidArgumentException('Agent ID is required');
        }

        if ($assignedBy === '') {
            throw new \InvalidArgumentException('Assigned by is required');
        }

        $this->ultimoTipoDeAsignacion = null;

        $result = $this->em->getConnection()->transactional(function () use ($ticketId, $agentId, $assignedBy, $notes) {
            // Find the agent
            $agent = $this->agentRepo->findById($agentId);
            if (!$agent) {
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
            $isActiveNow = $this->agentAvailabilityService->isActivoAhora(
                $agent,
                $agent->getShifts()->toArray(),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            if (!$isActiveNow) {
                throw AgenteInactivoException::paraAgente($agentId, $agent->getName());
            }

            // Detect assignment type from current ownership
            $currentOwnerId = $ticket->getAgenteAsignadoId();
            $previousAgent = null;

            if ($currentOwnerId !== null && $currentOwnerId !== $agentId) {
                // REASSIGN — ticket has a different owner
                $previousAgent = $this->agentRepo->findById($currentOwnerId);
            }

            $type = $currentOwnerId !== null ? TipoDeAsignacion::REASIGNACION : TipoDeAsignacion::MANUAL;
            $this->ultimoTipoDeAsignacion = $type->value;

            // Route through ResponsabilidadDeTicketService (creates audit row, updates ticket, emits events)
            $assignment = $this->assignmentService->registrarCambioDeResponsable(
                ticket: $ticket,
                agent: $agent,
                previousAgent: $previousAgent,
                type: $type,
                assignedBy: $assignedBy,
                notes: $notes,
            );

            $this->em->flush();

            return [
                'assignment_id' => $assignment->getId(),
                'ticket_id' => $ticket->getId(),
                'agent_id' => $assignment->getAgenteId(),
                'agent_name' => $agent->getName(),
                'assigned_by' => $assignment->getAsignadoPor(),
                'assigned_at' => $assignment->getAsignadoAt()->format('Y-m-d H:i:s'),
                'ticket_status' => $ticket->getEstado(),
                'notes' => $assignment->getNotas(),
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
