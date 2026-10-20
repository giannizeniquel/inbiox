<?php

declare(strict_types=1);

namespace App\Asignacion\UseCase;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Asignacion\ResponsabilidadDeTicketService;
use App\Asignacion\TipoDeAsignacion;
use App\Compartido\RecolectorDeEventos;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketUpdatedEvent;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Ticket\EstadoDeTicket;
use App\Disponibilidad\AgenteInactivoException;
use App\Agente\Repository\AgenteRepository;
use App\Asignacion\Repository\AsignacionRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/**
 * Asignación manual de un ticket por un supervisor.
 *
 * Pasa por ResponsabilidadDeTicketService para el registro de auditoría — la ÚNICA vía
 * que modifica ticket.assigned_agent_id.
 *
 * Tipos de asignación detectados:
 *   - MANUAL_ASSIGN: el ticket no tenía responsable → asignación inicial
 *   - REASSIGN:      el ticket ya tenía responsable → transferencia
 */
class AsignarTicketUseCase
{
    /** Guarda el último tipo de asignación para enriquecer el payload del outbox. */
    private ?string $ultimoTipoDeAsignacion = null;

    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private AsignacionRepository $asignacionRepo,
        private AgenteRepository $agentRepo,
        private RecolectorDeEventos $recolectorDeEventos,
        private readonly OutboxWriter $outboxWriter,
        private ResponsabilidadDeTicketService $responsabilidadDeTicketService,
        private DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
    ) {}

    /**
     * Asigna un ticket a un agente (asignación manual).
     *
     * @param int $ticketId
     * @param int $agenteId
     * @param string $asignadoPor
     * @param string|null $notas
     * @return array
     * @throws \InvalidArgumentException si no se encuentra el ticket o el agente
     */
    public function asignar(int $ticketId, int $agenteId, string $asignadoPor, ?string $notas = null): array
    {
        // Valida la entrada
        if ($agenteId <= 0) {
            throw new \InvalidArgumentException('Agent ID is required');
        }

        if ($asignadoPor === '') {
            throw new \InvalidArgumentException('Assigned by is required');
        }

        $this->ultimoTipoDeAsignacion = null;

        $result = $this->em->getConnection()->transactional(function () use ($ticketId, $agenteId, $asignadoPor, $notas) {
            // Busca el agente
            $agente = $this->agentRepo->findById($agenteId);
            if (!$agente) {
                throw new \InvalidArgumentException('Agent not found');
            }

            // Busca el ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
            }

            // Bloquea el ticket para evitar modificaciones concurrentes
            $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);

            // Guarda de Activo al asignar: BLOQUEO TOTAL para todos los roles, sin excepciones.
            // Aquí nunca se verifica la capacidad — solo cuenta activa + override/turno.
            $isActivoAhora = $this->disponibilidadDeAgenteService->isActivoAhora(
                $agente,
                $agente->getTurnos()->toArray(),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            if (!$isActivoAhora) {
                throw AgenteInactivoException::paraAgente($agenteId, $agente->getNombre());
            }

            // Detecta el tipo de asignación según el responsable actual
            $responsableActualId = $ticket->getAgenteAsignadoId();
            $agenteAnterior = null;

            if ($responsableActualId !== null && $responsableActualId !== $agenteId) {
                // REASSIGN — el ticket tiene otro responsable
                $agenteAnterior = $this->agentRepo->findById($responsableActualId);
            }

            $tipo = $responsableActualId !== null ? TipoDeAsignacion::REASIGNACION : TipoDeAsignacion::MANUAL;
            $this->ultimoTipoDeAsignacion = $tipo->value;

            // Pasa por ResponsabilidadDeTicketService (crea la fila de auditoría, actualiza el ticket, emite eventos)
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
                'asignacionId' => $asignacion->getId(),
                'ticketId' => $ticket->getId(),
                'agenteId' => $asignacion->getAgenteId(),
                'agenteNombre' => $agente->getNombre(),
                'asignadoPor' => $asignacion->getAsignadoPor(),
                'asignadoAt' => $asignacion->getAsignadoAt()->format('Y-m-d H:i:s'),
                'estadoDeTicket' => $ticket->getEstado(),
                'notas' => $asignacion->getNotas(),
                'resultado' => 'ok',
            ];
        });

        // Después del commit, persiste en el outbox los eventos recolectados
        $this->persistirEventosEnOutbox();

        return $result;
    }

    /**
     * Persiste en la tabla del outbox los eventos de dominio recolectados.
     */
    private function persistirEventosEnOutbox(): void
    {
        $events = $this->recolectorDeEventos->getEventos();
        if (empty($events)) {
            return;
        }

        $eventosOutbox = [];

        foreach ($events as $event) {
            $payload = [
                'eventId' => $event->eventId->toRfc4122(),
                'type' => $event->type,
                'ticketId' => $event->ticketId,
                'occurredAt' => $event->occurredAt->format(\DateTimeInterface::RFC3339),
                'seq' => $event->seq,
                'version' => $event->version,
            ];

            // Agrega los campos propios de cada evento
            if ($event instanceof TicketUpdatedEvent) {
                $payload['status'] = $event->status?->value;
                $payload['ownerAgentId'] = $event->ownerAgentId;
                $payload['assignee_id'] = $event->ownerAgentId;
                $payload['assignment_type'] = $this->ultimoTipoDeAsignacion;
            }

            $eventoOutbox = new EventoOutbox(
                aggregateType: 'ticket',
                aggregateId: $event->ticketId,
                topic: 'tickets',
                tipoDeEvento: $event->type,
                payload: $payload,
            );
            $eventosOutbox[] = $eventoOutbox;
        }

        $this->outboxWriter->write(...$eventosOutbox);
        $this->recolectorDeEventos->limpiar();
    }
}
