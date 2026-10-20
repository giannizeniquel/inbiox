<?php

declare(strict_types=1);

namespace App\Asignacion\UseCase;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Asignacion\ResponsabilidadDeTicketService;
use App\Agente\Agente;
use App\Asignacion\TipoDeAsignacion;
use App\Compartido\RecolectorDeEventos;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Ticket\EstadoDeTicket;
use App\Agente\Repository\AgenteRepository;
use App\Asignacion\Repository\AsignacionRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Asignación automática determinista de tickets al agente elegible con menos carga.
 *
 * Pipeline (una sola transacción):
 *   1. SELECT del ticket FOR UPDATE (idempotente)
 *   2. listarAgentesDisponibles() mediante DisponibilidadDeAgenteService (ÚNICA verificación de elegibilidad)
 *   3. SELECT de agentes FOR UPDATE WHERE id IN (...)
 *   4. Vuelve a filtrar por capacidad dentro del bloqueo (el COUNT puede haber cambiado)
 *   5. Desempate: menor cantidad de activos → menos AUTO_ASSIGN hoy → menor id
 *   6. ResponsabilidadDeTicketService.registrarCambioDeResponsable()
 *   7. Recolecta eventos de outbox para Mercure (PR4)
 *
 * Se ELIMINÓ el antiguo filtro fijo "WHERE active = 1 AND is_available = 1".
 * DisponibilidadDeAgenteService es la ÚNICA verificación de elegibilidad.
 */
final class AsignarTicketAutomaticamenteUseCase
{
    /** Guarda el último tipo de asignación para enriquecer el payload del outbox. */
    private ?string $ultimoTipoDeAsignacion = null;

    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private AsignacionRepository $asignacionRepo,
        private DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
        private ResponsabilidadDeTicketService $responsabilidadDeTicketService,
        private RecolectorDeEventos $recolectorDeEventos,
        private readonly OutboxWriter $outboxWriter,
        private AgenteRepository $agentRepo,
    ) {}

    /**
     * Ejecuta la asignación automática en su propia transacción.
     *
     * @param int $ticketId
     * @return array{ticket_id: int, assigned: bool, agent_id: ?int, reason: string}
     */
    public function execute(int $ticketId): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($ticketId) {
            return $this->executeInternal($ticketId);
        });

        // Después del commit, persiste en el outbox los eventos recolectados (para PR4)
        $this->persistirEventosEnOutbox();

        return $result;
    }

    /**
     * Ejecuta la asignación automática SIN iniciar una transacción nueva.
     * Usar cuando ya se está dentro de una transacción (p. ej., el listener de mensajes entrantes).
     *
     * @param int $ticketId
     * @return array{ticket_id: int, assigned: bool, agent_id: ?int, reason: string}
     */
    public function executeInTransaction(int $ticketId): array
    {
        return $this->executeInternal($ticketId);
    }

    /**
     * Lógica interna de ejecución — debe llamarse dentro de una transacción.
     */
    private function executeInternal(int $ticketId): array
    {
        // 1. Bloquea la fila del ticket FOR UPDATE
        $filaDeTicket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

        if (!$filaDeTicket) {
            return [
                'ticketId' => $ticketId,
                'asignado' => false,
                'agenteId' => null,
                'motivo' => 'ticket_not_found',
            ];
        }

        // 2. Si ya está asignado, devuelve de forma idempotente (sin nueva fila de Asignacion)
        if ($filaDeTicket['assigned_agent_id'] !== null) {
            return [
                'ticketId' => $ticketId,
                'asignado' => false,
                'agenteId' => (int) $filaDeTicket['assigned_agent_id'],
                'motivo' => 'already_assigned',
            ];
        }

        // 3. Obtiene los agentes disponibles mediante DisponibilidadDeAgenteService (ÚNICA verificación de elegibilidad)
        //    Usa la cascada completa: activo → override → horario → capacidad.
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $agentesDisponibles = $this->disponibilidadDeAgenteService->listarAgentesDisponibles($nowUtc);

        if (empty($agentesDisponibles)) {
            return [
                'ticketId' => $ticketId,
                'asignado' => false,
                'agenteId' => null,
                'motivo' => 'no_agents_available',
            ];
        }

        // 4. Bloquea las filas de los agentes candidatos FOR UPDATE
        $agenteIds = array_map(fn (Agente $a): int => (int) $a->getId(), $agentesDisponibles);
        $this->agentRepo->lockRowsForUpdate($agenteIds);

        // 5. Vuelve a filtrar por capacidad dentro del bloqueo + reúne los datos de desempate
        $inicioDeHoy = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTime(0, 0, 0);
        $elegibles = [];

        foreach ($agentesDisponibles as $agente) {
            $cantidadDeActivos = $this->ticketRepo->countActivosDeAgente((int) $agente->getId());
            if ($cantidadDeActivos >= $agente->getMaxTicketsActivos()) {
                continue; // sin capacidad — se omite
            }

            $cantidadDeHoy = $this->asignacionRepo->countAsignacionesAutomaticasDeHoy(
                (int) $agente->getId(),
                $inicioDeHoy,
            );

            $elegibles[] = [
                'agent' => $agente,
                'activeCount' => $cantidadDeActivos,
                'todayCount' => $cantidadDeHoy,
            ];
        }

        if (empty($elegibles)) {
            return [
                'ticketId' => $ticketId,
                'asignado' => false,
                'agenteId' => null,
                'motivo' => 'all_agents_at_capacity',
            ];
        }

        // 6. Desempate DETERMINISTA (sin shuffle ni rand — mismos datos = mismo resultado)
        usort($elegibles, function (array $a, array $b): int {
            // Primero: menor cantidad de tickets activos
            if ($a['activeCount'] !== $b['activeCount']) {
                return $a['activeCount'] <=> $b['activeCount'];
            }
            // Segundo: menos AUTO_ASSIGN hoy
            if ($a['todayCount'] !== $b['todayCount']) {
                return $a['todayCount'] <=> $b['todayCount'];
            }
            // Tercero: menor agent.id
            return $a['agent']->getId() <=> $b['agent']->getId();
        });

        $agenteSeleccionado = $elegibles[0]['agent'];

        // 7. Carga la entidad Ticket para ResponsabilidadDeTicketService
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            // No debería ocurrir — ya bloqueamos la fila arriba
            return [
                'ticketId' => $ticketId,
                'asignado' => false,
                'agenteId' => null,
                'motivo' => 'ticket_not_found',
            ];
        }

        // 8. Registra el cambio de responsable mediante ResponsabilidadDeTicketService (único punto de cambio)
        //    Esto crea la fila de auditoría de Asignacion, actualiza ticket.assigned_agent_id
        //    y emite los eventos de dominio.
        $this->ultimoTipoDeAsignacion = TipoDeAsignacion::AUTOMATICA->value;
        $this->responsabilidadDeTicketService->registrarCambioDeResponsable(
            ticket: $ticket,
            agente: $agenteSeleccionado,
            agenteAnterior: null,
            tipo: TipoDeAsignacion::AUTOMATICA,
            asignadoPor: 'system',
        );

        // 9. Flush para persistir todos los cambios en la transacción actual
        $this->em->flush();

        return [
            'ticketId' => $ticketId,
            'asignado' => true,
            'agenteId' => $agenteSeleccionado->getId(),
            'motivo' => 'success',
        ];
    }

    /**
     * Persiste en la tabla del outbox los eventos de dominio recolectados (post-commit).
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

            if ($event instanceof \App\Ticket\TicketUpdatedEvent) {
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
