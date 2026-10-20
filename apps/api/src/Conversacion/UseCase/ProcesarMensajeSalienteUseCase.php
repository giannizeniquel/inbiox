<?php

namespace App\Conversacion\UseCase;

use App\Asignacion\UseCase\AsignarTicketUseCase;
use App\Asignacion\UseCase\AsignarTicketAutomaticamenteUseCase;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;

use App\Asignacion\ResponsabilidadDeTicketService;
use App\Asignacion\TipoDeAsignacion;
use App\Conversacion\Conversacion;
use App\Conversacion\EstadoDeConversacion;
use App\Compartido\RecolectorDeEventos;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketUpdatedEvent;
use App\Conversacion\MessageCreatedEvent;
use App\Conversacion\Mensaje;
use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\TipoDeRemitente;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Compartido\EventoProcesado;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Automatizacion\EjecutorDeReglas;
use App\Automatizacion\ContextoDeRegla;
use App\Conversacion\PoliticaDeVentana;
use App\Ticket\TicketResponsabilidadGuard;
use App\Compartido\ConcurrencyException;
use App\Conversacion\VentanaVencidaException;
use App\Compartido\ConflictoDeEstadoException;
use App\Agente\Repository\AgenteRepository;
use App\Conversacion\Repository\ConversacionRepository;
use App\Conversacion\Repository\MensajeRepository;
use App\Compartido\Repository\EventoProcesadoRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Ulid;

/**
 * Handles outbound (agent) messages.
 * Transitions ticket status based on FSM rules.
 * Sets first_response_at on first agent reply.
 * Implements idempotency: duplicate messages are skipped.
 */
class ProcesarMensajeSalienteUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConversacionRepository $conversacionRepo,
        private MensajeRepository $mensajeRepo,
        private TicketRepository $ticketRepo,
        private CambiarEstadoDeTicketUseCase $cambiarEstadoDeTicketUseCase,
        private EventoProcesadoRepository $processedEventRepo,
        private EjecutorDeReglas $ejecutorDeReglas,
        private ResponsabilidadDeTicketService $responsabilidadDeTicketService,
        private AgenteRepository $agenteRepo,
        private RecolectorDeEventos $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private PoliticaDeVentana $politicaDeVentana,
    ) {}

    /**
     * Process outbound message from agent
     * Implements idempotency: duplicate messages (same ticket + content) are skipped.
     * Implements optimistic locking retry with exponential backoff.
     *
     * @param int $ticketId
     * @param string $contenido
     * @param int $agenteId
     * @param array|null $metadatosDeAdjunto Optional media metadata: {key, mimeType, filename, size, kind, providerId, caption}
     * @return array
     */
    public function procesarMensajeSaliente(
        int $ticketId,
        string $contenido,
        int $agenteId,
        ?array $metadatosDeAdjunto = null,
        bool $remitenteEsPrivilegiado = false,
    ): array {
        // Generate deterministic event key: outbound:{ticketId}:{contentHash}
        // Media messages have empty content (caption lives in media metadata), so
        // hash the provider media ID (unique per upload) to avoid dedup collisions.
        $mediaId = $metadatosDeAdjunto['providerId'] ?? null;
        $hashSource = $mediaId !== null ? 'media:' . $mediaId : 'text:' . $contenido;
        $contentHash = md5($hashSource);
        $eventKey = sprintf('outbound:%d:%s', $ticketId, $contentHash);

        // Check idempotency: return early if already processed (no retry needed)
        $existingEvent = $this->processedEventRepo->findByEventoId($eventKey);
        if ($existingEvent !== null) {
            return [
                'ticket_id' => $ticketId,
                'message_id' => null,
                'previous_status' => null,
                'new_status' => null,
                'first_response_set' => false,
                'transition' => 'none',
                'status' => 'duplicate_skipped',
                'event_key' => $eventKey,
                'cached_result' => $existingEvent->getDatosDeResultado(),
            ];
        }

        // Retry logic for optimistic locking
        $maxRetries = 3;
        $attempt = 0;
        $ticket = null;
        $result = null;

        while ($attempt < $maxRetries) {
            try {
                $result = $this->executeTransactionWithRetry($ticketId, $contenido, $eventKey, $agenteId, $ticket, $metadatosDeAdjunto, $remitenteEsPrivilegiado);
                break;
            } catch (OptimisticLockException $e) {
                // A rolled-back attempt must not leak its collected events
                // (e.g. TicketAssigned) into the next attempt's flush —
                // otherwise the retry re-collects the same events again,
                // producing a phantom event with a duplicate seq
                // (design.md D6).
                $this->eventCollector->limpiar();

                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw new ConcurrencyException(
                        'Max retries exceeded for optimistic locking on ticket ' . $ticketId,
                        $e
                    );
                }

                // Exponential backoff: 100ms, 200ms, 400ms
                $sleepMs = $attempt * $attempt * 100;
                usleep($sleepMs * 1000);

                // Refresh ticket state before retry
                if ($ticket !== null) {
                    $this->em->refresh($ticket);
                }

                // Clear entity manager to avoid stale state
                $this->em->clear();
            } catch (\Throwable $e) {
                // Any other abort (e.g. ConflictoDeEstadoException,
                // VentanaVencidaException) must also discard
                // whatever this attempt collected before propagating —
                // there is no retry for these, but the collector must not
                // carry stale events into some later, unrelated call
                // (design.md D6).
                $this->eventCollector->limpiar();
                throw $e;
            }
        }

        if ($result === null) {
            // Should never reach here, but satisfy static analysis
            throw new ConcurrencyException('Unexpected error in retry loop');
        }

        // Persist events AFTER the transaction fully commits (moved OUT of
        // the try block, matching AsignarTicketUseCase:118-121 /
        // AsignarTicketAutomaticamenteUseCase:55-62): keeping the outbox flush inside
        // the try let an outbox-flush OptimisticLockException re-execute
        // the WHOLE transaction and re-collect duplicate events
        // (design.md D6).
        $this->persistEventsToOutbox();

        return $result;
    }

    /**
     * Runs one attempt of the outbound flow inside a transaction. $ticket is
     * set as soon as the row is locked, so the retry loop can refresh it.
     */
    private function executeTransactionWithRetry(
        int $ticketId,
        string $contenido,
        string $eventKey,
        int $agenteId,
        ?Ticket &$ticket,
        ?array $metadatosDeAdjunto = null,
        bool $remitenteEsPrivilegiado = false,
    ): array {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $contenido, $eventKey, $agenteId, &$ticket, $metadatosDeAdjunto, $remitenteEsPrivilegiado) {
            // Get ticket — pessimistic locking read as the FIRST statement of
            // the transaction (design.md D1). Under MySQL REPEATABLE READ, a
            // plain findById() opens a consistent-read snapshot BEFORE any
            // lock is acquired; a later refresh() would still read that
            // stale snapshot. A locking read here blocks until any
            // conflicting transaction commits, then HINT_REFRESH hydrates
            // from the latest-committed row.
            $ticket = $this->ticketRepo->findByIdForUpdate($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
            }

            $omitirCambioDeResponsable = TicketResponsabilidadGuard::isResueltoOCerrado($ticket->getEstado());
            $this->verificarEnvio($ticket, $ticketId, $agenteId, $remitenteEsPrivilegiado);

            // Get conversation
            $conversacion = $ticket->getConversacion();
            if (!$conversacion) {
                throw new \InvalidArgumentException('Ticket has no conversation');
            }

            // Skipped on resolved/closed tickets — ownership is frozen there.
            if (!$omitirCambioDeResponsable) {
                $this->tomarTicketParaAgenteQueResponde($ticket, $agenteId);
            }

            $mensaje = $this->createMensajeSaliente($conversacion, $contenido, $agenteId, $metadatosDeAdjunto);

            [$estadoActual, $nuevoEstado, $transicion, $debeRegistrarPrimeraRespuesta] = $this->aplicarTransicionDeRespuesta($ticket, $ticketId);

            // Update conversation timestamps
            $conversacion->setUltimoMensajeAt($mensaje->getCreadoAt());
            $conversacion->setActualizadoAt(new \DateTimeImmutable());
            $ticket->marcarActualizado();

            // Store processed event for idempotency
            $resultData = [
                'ticket_id' => $ticketId,
                'message_id' => $mensaje->getId(),
                'previous_status' => $estadoActual,
                'new_status' => $nuevoEstado,
                'first_response_set' => $debeRegistrarPrimeraRespuesta,
                'transition' => $transicion ?? 'none',
            ];
            $processedEvent = new EventoProcesado($eventKey);
            $processedEvent->setDatosDeResultado($resultData);
            $this->em->persist($processedEvent);

            $this->em->flush();

            $this->registrarMessageCreatedEvent($ticket, $ticketId, $mensaje, $contenido, $agenteId);

            // Flush again to persist the bumped lastEventSeq (design.md D5).
            $this->em->flush();

            // Execute automation rules (outbound trigger)
            $contextoDeRegla = new ContextoDeRegla($ticket, $mensaje, 'outbound');
            $this->ejecutorDeReglas->execute($contextoDeRegla);

            return [
                'ticket_id' => $ticketId,
                'message_id' => $mensaje->getId(),
                'previous_status' => $estadoActual,
                'new_status' => $nuevoEstado,
                'first_response_set' => $debeRegistrarPrimeraRespuesta,
                'transition' => $transicion ?? 'none',
                'status' => 'ok',
                'event_key' => $eventKey,
            ];
        });
    }

    /**
     * Rejects the send before anything is persisted.
     *
     * Resolved/closed ownership guard (design.md D3-D5, spec.md
     * "Resolved/Closed Ticket Ownership Guard"): a non-owning, non-privileged
     * agent may not claim/reassign a resolved/closed ticket by replying. It
     * runs BEFORE the window-policy check — a 409 here is more informative
     * than a misleading 422, and ownership on a closed ticket is
     * stable/authoritative while the window is merely time-dependent.
     *
     * WhatsApp 24h conversation-window guard-before-persist (design.md D3/D4):
     * free text is rejected once the effective window has elapsed, before any
     * message row is persisted and before any FSM transition — never a
     * phantom "sent" message.
     */
    private function verificarEnvio(Ticket $ticket, int $ticketId, int $agenteId, bool $remitenteEsPrivilegiado): void
    {
        $estado = $ticket->getEstado();
        if (TicketResponsabilidadGuard::bloqueaReasignacion($estado, $remitenteEsPrivilegiado, $agenteId, $ticket->getAgenteAsignadoId())) {
            throw new ConflictoDeEstadoException(sprintf(
                'Cannot send to ticket in "%s" state: sender is not the assigned owner',
                $estado
            ));
        }

        if (!$this->politicaDeVentana->isAbiertaParaEnviar($ticket->getUltimoMensajeEntranteAt(), new \DateTimeImmutable())) {
            throw new VentanaVencidaException($ticketId, $ticket->getUltimoMensajeEntranteAt());
        }
    }

    /**
     * Assigns the ticket to the replying agent when someone else (or nobody)
     * owns it. An unknown agent, or agent id 0, leaves the ticket as it is.
     */
    private function tomarTicketParaAgenteQueResponde(Ticket $ticket, int $agenteId): void
    {
        $responsableActualId = $ticket->getAgenteAsignadoId();
        if ($agenteId <= 0 || $responsableActualId === $agenteId) {
            return;
        }

        $agente = $this->agenteRepo->findById($agenteId);
        if ($agente === null) {
            return;
        }

        $agenteAnterior = null;
        if ($responsableActualId !== null) {
            $agenteAnterior = $this->agenteRepo->findById($responsableActualId);
        }

        $tipo = $responsableActualId !== null
            ? TipoDeAsignacion::REASIGNACION
            : TipoDeAsignacion::AUTOMATICA;

        $this->responsabilidadDeTicketService->registrarCambioDeResponsable(
            ticket: $ticket,
            agente: $agente,
            agenteAnterior: $agenteAnterior,
            tipo: $tipo,
            asignadoPor: 'system',
            notas: 'Auto-assigned on agent reply',
        );

        // Flush the assignment + any status change made by ResponsabilidadDeTicketService
        // (e.g. new → active) so the DB is consistent before we decide the
        // FSM transition below.
        $this->em->flush();
        $this->em->refresh($ticket);

        // When an agent replies and becomes the owner, mark as read
        $ticket->setUltimaLecturaAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function createMensajeSaliente(Conversacion $conversacion, string $contenido, int $agenteId, ?array $metadatosDeAdjunto): Mensaje
    {
        $mensaje = new Mensaje();
        $mensaje->setConversacion($conversacion);
        $mensaje->setDireccion('outbound');
        $mensaje->setContenido($contenido);
        $mensaje->setAgenteRemitenteId($agenteId > 0 ? $agenteId : null);
        $mensaje->setTipoDeRemitente(TipoDeRemitente::AGENTE);

        // Attach media metadata if provided
        if ($metadatosDeAdjunto !== null) {
            $mensaje->setAdjunto($metadatosDeAdjunto);
        }

        $this->em->persist($mensaje);

        return $mensaje;
    }

    /**
     * Moves the ticket after an agent reply (new → active, active → waiting,
     * waiting stays) and sets first_response_at on the first reply.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: bool} previous status, new status, transition name, whether first response was set
     */
    private function aplicarTransicionDeRespuesta(Ticket $ticket, int $ticketId): array
    {
        $estadoActual = $ticket->getEstado();
        $debeRegistrarPrimeraRespuesta = empty($ticket->getPrimeraRespuestaAt());
        $nuevoEstado = $estadoActual;
        $transicion = null;

        if ($estadoActual === EstadoDeTicket::NUEVO->value) {
            // First agent response: new → active
            $nuevoEstado = EstadoDeTicket::ACTIVO->value;
            $transicion = 'new_to_active';
        } elseif ($estadoActual === EstadoDeTicket::ACTIVO->value) {
            // Agent waiting for response: active → waiting
            $nuevoEstado = EstadoDeTicket::EN_ESPERA->value;
            $transicion = 'active_to_waiting';
        } elseif ($estadoActual === EstadoDeTicket::EN_ESPERA->value) {
            // Agent replies while waiting: stays waiting (customer might respond)
            $nuevoEstado = EstadoDeTicket::EN_ESPERA->value;
            $transicion = 'waiting_remains';
        }

        // Execute transition if needed
        if ($transicion && $nuevoEstado !== $estadoActual) {
            $this->cambiarEstadoDeTicketUseCase->execute(
                $ticketId,
                $nuevoEstado,
                $debeRegistrarPrimeraRespuesta,
                false
            );
        } else if ($debeRegistrarPrimeraRespuesta) {
            // Just set first_response_at without status change
            $this->ticketRepo->marcarPrimeraRespuesta($ticketId);
        }

        return [$estadoActual, $nuevoEstado, $transicion, $debeRegistrarPrimeraRespuesta];
    }

    /**
     * Collects MessageCreatedEvent for human-agent replies (design.md D5), so
     * outbound messages are broadcast over Mercure. The message must already
     * be flushed, because the event carries its id.
     */
    private function registrarMessageCreatedEvent(Ticket $ticket, int $ticketId, Mensaje $mensaje, string $contenido, int $agenteId): void
    {
        $nombreDelRemitente = $agenteId > 0 ? $this->agenteRepo->findById($agenteId)?->getNombre() : null;
        $this->eventCollector->recolectar(new MessageCreatedEvent(
            eventId: new Ulid(),
            ticketId: $ticketId,
            occurredAt: new \DateTimeImmutable(),
            seq: $ticket->incrementLastEventSeq(),
            version: 1,
            direction: DireccionDeMensaje::SALIENTE,
            messageId: $mensaje->getId(),
            content: $contenido,
            senderName: $nombreDelRemitente,
            media: $mensaje->getAdjunto(),
        ));
    }

    /**
     * Persist collected domain events to outbox for Mercure real-time.
     * After auto-assignment, events like TicketAssigned need to be published.
     */
    private function persistEventsToOutbox(): void
    {
        $events = $this->eventCollector->getEventos();
        if (empty($events)) {
            return;
        }

        $outboxEvents = [];

        foreach ($events as $event) {
            $payload = [
                'event_id' => $event->eventId->toRfc4122(),
                'type' => $event->type,
                'ticket_id' => $event->ticketId,
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::RFC3339),
                'seq' => $event->seq,
                'version' => $event->version,
            ];

            if ($event instanceof TicketUpdatedEvent) {
                $payload['status'] = $event->status;
                if ($event->ownerAgentId !== null) {
                    $payload['assignee_id'] = $event->ownerAgentId;
                    // Fetch agent name for the frontend
                    $agente = $this->agenteRepo->findById($event->ownerAgentId);
                    if ($agente !== null) {
                        $payload['assignee_name'] = $agente->getNombre();
                    }
                }
            } elseif ($event instanceof InboxUpdatedEvent) {
                // Skip inbox events for now (they don't have a wire format yet)
                continue;
            } elseif ($event instanceof MessageCreatedEvent) {
                $payload['message_id'] = $event->messageId;
                $payload['direction'] = $event->direction->value;
                $payload['content'] = $event->content;
                $payload['sender_name'] = $event->senderName;
                $payload['media'] = $event->media;
                // Include phone_number_id for workspace-pending indicator
                $phoneNumberId = $this->ticketRepo->findPhoneNumberId($event->ticketId);
                if (!empty($phoneNumberId)) {
                    $payload['phone_number_id'] = $phoneNumberId;
                }
            }

            $outboxEvent = new EventoOutbox(
                aggregateType: 'ticket',
                aggregateId: (int) $event->ticketId,
                topic: 'tickets',
                eventType: $event->type,
                payload: $payload
            );
            $outboxEvents[] = $outboxEvent;
        }

        $this->outboxWriter->write(...$outboxEvents);
        $this->eventCollector->limpiar();
    }
}