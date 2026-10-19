<?php

namespace App\Conversacion\UseCase;

use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Conversacion\EstadoDeConversacion;
use App\Compartido\RecolectorDeEventos;
use App\Conversacion\MessageCreatedEvent;
use App\Ticket\TicketCreatedEvent;
use App\Conversacion\Mensaje;
use App\Conversacion\TipoDeRemitente;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Contacto\Repository\ContactoRepository;
use App\Conversacion\Repository\ConversacionRepository;
use App\Conversacion\Repository\MensajeRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use App\Automatizacion\EjecutorDeReglas;
use App\Automatizacion\RespuestaAutomaticaService;
use App\Automatizacion\ContextoDeRegla;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use App\Conversacion\DescargaDeAdjuntoService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use App\Asignacion\TicketRequiereAsignacionAutomaticaEvent;
use Symfony\Component\Uid\Ulid;

class ProcesarMensajeEntranteUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactoRepository $contactoRepo,
        private ConversacionRepository $conversacionRepo,
        private MensajeRepository $mensajeRepo,
        private TicketRepository $ticketRepo,
        private EventDispatcherInterface $eventDispatcher,
        private CambiarEstadoDeTicketUseCase $cambiarEstadoDeTicketUseCase,
        private EjecutorDeReglas $ejecutorDeReglas,
        private RecolectorDeEventos $eventCollector,
        private readonly OutboxWriter $outboxWriter,
        private RespuestaAutomaticaService $respuestaAutomaticaService,
        private LoggerInterface $logger,
        private readonly ?DescargaDeAdjuntoService $descargaDeAdjuntoService = null,
    ) {}

    /**
     * Process an incoming message from a webhook or API call.
     *
     * @param string      $telefono           Sender's phone number in E.164 format
     * @param string      $texto         Message content
     * @param string|null $externalId      WAMID from Meta (for idempotency)
     * @param string|null $phoneNumberId   WhatsApp phone number ID that received this message (CAP-7)
     * @param string|null $nombreDeContacto     Optional contact name to set on new/existing contacts
     * @param array|null  $datosDeDescargaDeAdjunto  Optional media payload: {mediaId, mimeType, filename, caption}
     *
     * @return array{contact_id: int, conversation_id: int, message_id: int, ticket_id: int|null, status: string}
     */
    public function procesarMensajeEntrante(
        string $telefono,
        string $texto,
        ?string $externalId = null,
        ?string $phoneNumberId = null,
        ?string $nombreDeContacto = null,
        ?array $datosDeDescargaDeAdjunto = null,
    ): array {
        // 1. Normalize phone: strip non-digits, keep format as-is (WhatsApp sends correct prefix)
        $telefonoNormalizado = preg_replace('/[^0-9]/', '', $telefono);
        if (strlen($telefonoNormalizado) < 10) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        $telefonoNormalizado = '+' . $telefonoNormalizado;

        $result = $this->em->getConnection()->transactional(function() use ($telefonoNormalizado, $texto, $externalId, $phoneNumberId, $nombreDeContacto, $datosDeDescargaDeAdjunto) {
            return $this->executeInternal($telefonoNormalizado, $texto, $externalId, $phoneNumberId, $nombreDeContacto, $datosDeDescargaDeAdjunto);
        });

        // After commit, attempt auto-reply (fire-and-forget)
        // This runs BEFORE persistEventsToOutbox to keep ordering deterministic
        try {
            $ticketId = $result['ticket_id'] ?? null;
            if ($ticketId !== null) {
                $ticket = $this->ticketRepo->find($ticketId);
                if ($ticket !== null) {
                    $this->respuestaAutomaticaService->enviarSiCorresponde($ticket);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AutoReply skipped due to error: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        // After commit, persist collected events to outbox
        $this->persistEventsToOutbox();

        return $result;
    }

    /**
     * Persist collected domain events to outbox table
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
                'event_id' => $event->eventId->toRfc4122(),
                'type' => $event->type,
                'ticket_id' => $event->ticketId,
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::RFC3339),
                'seq' => $event->seq,
                'version' => $event->version,
            ];

            // Add event-specific fields
            if ($event instanceof MessageCreatedEvent) {
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
            } elseif ($event instanceof TicketCreatedEvent) {
                $payload['status'] = $event->status->value;
                $payload['priority'] = $event->priority;
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
        $this->eventCollector->clear();
    }

    /**
     * Runs inside the inbound transaction. The steps keep their original
     * order: three flushes (message, media, everything else), the media
     * download from Graph before the ticket lock, and the event sequence
     * increments after the last flush.
     */
    private function executeInternal(
        string $telefono,
        string $texto,
        ?string $externalId,
        ?string $phoneNumberId = null,
        ?string $nombreDeContacto = null,
        ?array $datosDeDescargaDeAdjunto = null,
    ): array {
        $contacto = $this->findOrCreateContacto($telefono, $nombreDeContacto);
        $conversacion = $this->findOrCreateConversacionAbierta($contacto, $phoneNumberId);

        // Idempotency by WAMID. It runs after the contact and conversation
        // may have been created, so a duplicate still stores those.
        $mensajeExistente = $this->findMensajeYaGuardado($externalId);
        if ($mensajeExistente) {
            return [
                'contact_id' => $contacto->getId(),
                'conversation_id' => $conversacion->getId(),
                'message_id' => $mensajeExistente->getId(),
                'ticket_id' => $this->getTicketDeConversacion($conversacion)?->getId(),
                'status' => 'ok'
            ];
        }

        $mensaje = $this->guardarMensajeEntrante($conversacion, $texto, $externalId);
        $this->descargarAdjuntoEnLaTransaccion($mensaje, $datosDeDescargaDeAdjunto);

        [$ticket, $ticketNuevo] = $this->findOrReabrirOrCreateTicketBloqueado($contacto, $conversacion, $phoneNumberId);
        $transicion = $this->activarTicketEnEspera($ticket);

        $this->actualizarFechas($conversacion, $contacto, $ticket, $mensaje);
        $this->em->flush();

        $this->registrarEventosDeEntrada($ticket, $ticketNuevo, $mensaje, $texto, $contacto);
        $this->ejecutarReglasYPedirAsignacionAutomatica($ticket, $mensaje);

        return [
            'contact_id' => $contacto->getId(),
            'conversation_id' => $conversacion->getId(),
            'message_id' => $mensaje->getId(),
            'ticket_id' => $ticket->getId(),
            'ticket_status' => $ticket->getEstado(),
            'transition' => $transicion,
            'status' => 'ok'
        ];
    }

    private function findOrCreateContacto(string $telefono, ?string $nombreDeContacto): Contacto
    {
        $contacto = $this->contactoRepo->findOneByTelefono($telefono);
        if (!$contacto) {
            $contacto = new Contacto();
            $contacto->setTelefono($telefono);
            if ($nombreDeContacto !== null) {
                $contacto->setNombre($nombreDeContacto);
            }
            $this->em->persist($contacto);
        } elseif ($nombreDeContacto !== null && $contacto->getNombre() === null) {
            // Update name on existing contact if it was missing
            $contacto->setNombre($nombreDeContacto);
        }

        return $contacto;
    }

    private function findOrCreateConversacionAbierta(Contacto $contacto, ?string $phoneNumberId): Conversacion
    {
        $conversacion = $this->conversacionRepo->findAbiertaByContacto($contacto, $phoneNumberId);
        if (!$conversacion) {
            $conversacion = new Conversacion();
            $conversacion->setContacto($contacto);
            $conversacion->setEstado(EstadoDeConversacion::ABIERTA->value);
            if ($phoneNumberId !== null) {
                $conversacion->setPhoneNumberId($phoneNumberId);
            }
            $this->em->persist($conversacion);
        }

        return $conversacion;
    }

    private function findMensajeYaGuardado(?string $externalId): ?Mensaje
    {
        if (!$externalId) {
            return null;
        }

        return $this->mensajeRepo->findByExternalId($externalId);
    }

    /** Persists the message and flushes (first flush), so it has an id for the media key. */
    private function guardarMensajeEntrante(Conversacion $conversacion, string $texto, ?string $externalId): Mensaje
    {
        $mensaje = new Mensaje();
        $mensaje->setConversacion($conversacion);
        $mensaje->setDireccion('inbound');
        $mensaje->setContenido($texto);
        if ($externalId) {
            $mensaje->setExternalId($externalId);
        }
        $mensaje->setTipoDeRemitente(TipoDeRemitente::CONTACTO);

        $this->em->persist($mensaje);
        $this->em->flush();

        return $mensaje;
    }

    /**
     * Downloads the media from Graph and stores it while the inbound
     * transaction is open (contradiction track, S10). A failure only logs:
     * the message stays stored without media. Success flushes (second flush).
     */
    private function descargarAdjuntoEnLaTransaccion(Mensaje $mensaje, ?array $datosDeDescargaDeAdjunto): void
    {
        if (!$datosDeDescargaDeAdjunto || $this->descargaDeAdjuntoService === null) {
            return;
        }

        try {
            $mediaId = $datosDeDescargaDeAdjunto['mediaId'];
            $mimeType = $datosDeDescargaDeAdjunto['mimeType'] ?? 'application/octet-stream';
            $filename = $datosDeDescargaDeAdjunto['filename'] ?? 'file';
            $leyenda = $datosDeDescargaDeAdjunto['caption'] ?? null;

            // Determine tenant and ticket for the object key
            $tenantId = 'default'; // Default tenant prefix
            $ticketId = $mensaje->getId(); // Use message ID as part of key

            $resultadoDeAdjunto = $this->descargaDeAdjuntoService->descargarYGuardar(
                mediaId: $mediaId,
                mimeType: $mimeType,
                tenantId: $tenantId,
                ticketId: $ticketId,
                filename: $filename,
            );

            if ($resultadoDeAdjunto !== null) {
                $mensaje->setAdjunto([
                    'key' => $resultadoDeAdjunto['key'],
                    'mimeType' => $mimeType,
                    'filename' => $filename,
                    'size' => $resultadoDeAdjunto['size'],
                    'kind' => $resultadoDeAdjunto['kind'],
                    'providerId' => $mediaId,
                    'caption' => $leyenda,
                ]);
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process inbound media', [
                'error' => $e->getMessage(),
                'mediaId' => $datosDeDescargaDeAdjunto['mediaId'] ?? null,
            ]);
            // Continue without media — message is already persisted
        }
    }

    /**
     * Uses the contact's not-closed ticket (resolved included, for the
     * auto-reopen), else reopens its window-expired closed ticket on the same
     * conversation, else creates a new one. An existing ticket is locked.
     *
     * @return array{0: Ticket, 1: bool} the ticket and whether it is new
     */
    private function findOrReabrirOrCreateTicketBloqueado(Contacto $contacto, Conversacion $conversacion, ?string $phoneNumberId): array
    {
        $ticket = $this->ticketRepo->findNoCerradoByContacto($contacto, $phoneNumberId);
        $fueReabierto = false;

        // WhatsApp 24h conversation-window compliance: a ticket closed
        // specifically by the window-expiry process reactivates on a new
        // inbound message instead of spawning a new ticket (design.md D1).
        // No human-facing endpoint can trigger this — see
        // CambiarEstadoDeTicketUseCase::reopenDueToInboundMessage()'s own
        // guard and TicketWorkflowControllerWindowIsolationTest.
        //
        // Known accepted limitation (proposal.md/design.md, deferred to a
        // future phase): a reopened ticket may not appear live in the
        // agent's ticket list, because no ticket-level Mercure event fires
        // for it — `TicketUpdatedEvent` is deliberately NOT extended here
        // (fixed constraint). The reopen is fully correct in the database
        // (status='active', closed_at=NULL, `ticket_events` audit row) and
        // becomes visible on the agent's next list load. Do NOT add a new
        // event emission here to close this gap — it is out of scope for
        // this change.
        if (!$ticket) {
            $reabrible = $this->ticketRepo->findCerradoPorVentanaVencidaByContacto($contacto, $phoneNumberId);
            if ($reabrible !== null
                && $reabrible->getConversacion()?->getId() === $conversacion->getId()) {
                $this->cambiarEstadoDeTicketUseCase->reabrirPorMensajeEntrante((int) $reabrible->getId());
                $ticket = $reabrible;
                $fueReabierto = true;
            }
        }

        if (!$ticket) {
            $ticket = new Ticket();
            $ticket->setContacto($contacto);
            $ticket->setConversacion($conversacion);
            $ticket->setEstado(EstadoDeTicket::NUEVO->value);
            $ticket->setPrioridad('medium');
            if ($phoneNumberId !== null) {
                $ticket->setPhoneNumberId($phoneNumberId);
            }
            $this->em->persist($ticket);

            return [$ticket, true];
        }

        // Existing ticket: lock to prevent seq duplicates under concurrency
        $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);

        // `reopenDueToInboundMessage()` writes via raw SQL, so the
        // managed entity fetched above still reports the stale
        // `status = 'closed'`. Without this refresh, the waiting→active
        // step (and the returned `ticket_status`) would read/report the
        // wrong status.
        if ($fueReabierto) {
            $this->em->refresh($ticket);
        }

        return [$ticket, false];
    }

    /**
     * FSM transition on a customer response. The transition writes with raw
     * SQL and the managed ticket keeps `waiting` (contradiction track, S10).
     */
    private function activarTicketEnEspera(Ticket $ticket): ?string
    {
        if ($ticket->getEstado() !== EstadoDeTicket::EN_ESPERA->value) {
            return null;
        }

        $this->cambiarEstadoDeTicketUseCase->execute(
            $ticket->getId(),
            EstadoDeTicket::ACTIVO->value,
            false, // don't set first_response - this is customer
            false  // don't set resolved
        );

        return 'waiting_to_active';
    }

    private function actualizarFechas(Conversacion $conversacion, Contacto $contacto, Ticket $ticket, Mensaje $mensaje): void
    {
        $conversacion->setUltimoMensajeAt($mensaje->getCreadoAt());
        $conversacion->setActualizadoAt(new \DateTimeImmutable());
        $contacto->setActualizadoAt(new \DateTimeImmutable());
        $ticket->setActualizadoAt(new \DateTimeImmutable());

        // WhatsApp 24h conversation-window compliance: this is the single
        // writer for `lastInboundMessageAt` (design.md, task 3.1/3.8) — it
        // must fire on every inbound message, for both a brand-new ticket
        // and a reused/reopened one, and on no other path (outbound sends
        // and auto-replies never touch this field).
        $ticket->setUltimoMensajeEntranteAt($mensaje->getCreadoAt());
    }

    /**
     * Collected after the last flush, so the ticket has its id. The sequence
     * increments are saved by the outbox writer's flush.
     */
    private function registrarEventosDeEntrada(Ticket $ticket, bool $ticketNuevo, Mensaje $mensaje, string $texto, Contacto $contacto): void
    {
        if ($ticketNuevo) {
            $this->eventCollector->collect(new TicketCreatedEvent(
                eventId: new Ulid(),
                ticketId: $ticket->getId(),
                occurredAt: new \DateTimeImmutable(),
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
                status: EstadoDeTicket::NUEVO,
                priority: 1 // medium = 1
            ));
        }

        $this->eventCollector->collect(new MessageCreatedEvent(
            eventId: new Ulid(),
            ticketId: $ticket->getId(),
            occurredAt: new \DateTimeImmutable(),
            seq: $ticket->incrementLastEventSeq(),
            version: 1,
            direction: \App\Conversacion\DireccionDeMensaje::ENTRANTE,
            messageId: $mensaje->getId(),
            content: $texto,
            senderName: $contacto->getNombre(),
            media: $mensaje->getAdjunto(),
        ));
    }

    /**
     * Inbound automation rules, then the auto-assignment request. Its
     * listener runs synchronously, inside the inbound transaction.
     */
    private function ejecutarReglasYPedirAsignacionAutomatica(Ticket $ticket, Mensaje $mensaje): void
    {
        $contextoDeRegla = new ContextoDeRegla($ticket, $mensaje, 'inbound');
        $this->ejecutorDeReglas->execute($contextoDeRegla);

        if ($ticket->getId()) {
            $this->eventDispatcher->dispatch(new TicketRequiereAsignacionAutomaticaEvent($ticket->getId()));
        }
    }

    private function getTicketDeConversacion(Conversacion $conversacion): ?Ticket
    {
        return $this->ticketRepo->findByConversacion($conversacion);
    }
}
