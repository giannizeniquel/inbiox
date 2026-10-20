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
 * Atiende los mensajes salientes (del agente).
 * Cambia el estado del ticket según las reglas de la FSM.
 * Asigna first_response_at en la primera respuesta del agente.
 * Implementa idempotencia: los mensajes duplicados se omiten.
 */
class ProcesarMensajeSalienteUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConversacionRepository $conversacionRepo,
        private MensajeRepository $mensajeRepo,
        private TicketRepository $ticketRepo,
        private CambiarEstadoDeTicketUseCase $cambiarEstadoDeTicketUseCase,
        private EventoProcesadoRepository $eventoProcesadoRepository,
        private EjecutorDeReglas $ejecutorDeReglas,
        private ResponsabilidadDeTicketService $responsabilidadDeTicketService,
        private AgenteRepository $agenteRepo,
        private RecolectorDeEventos $recolectorDeEventos,
        private readonly OutboxWriter $outboxWriter,
        private PoliticaDeVentana $politicaDeVentana,
    ) {}

    /**
     * Procesa un mensaje saliente del agente
     * Implementa idempotencia: los mensajes duplicados (mismo ticket + contenido) se omiten.
     * Implementa reintentos ante el bloqueo optimista con backoff exponencial.
     *
     * @param int $ticketId
     * @param string $contenido
     * @param int $agenteId
     * @param array|null $metadatosDeAdjunto Metadatos opcionales del adjunto: {key, mimeType, filename, size, kind, providerId, caption}
     * @return array
     */
    public function procesarMensajeSaliente(
        int $ticketId,
        string $contenido,
        int $agenteId,
        ?array $metadatosDeAdjunto = null,
        bool $remitenteEsPrivilegiado = false,
    ): array {
        // Genera una clave de evento determinista: outbound:{ticketId}:{contentHash}
        // Los mensajes con adjunto tienen contenido vacío (el caption vive en los metadatos
        // del adjunto), así que se hashea el media ID del proveedor (único por subida) para evitar colisiones de deduplicación.
        $mediaId = $metadatosDeAdjunto['providerId'] ?? null;
        $hashSource = $mediaId !== null ? 'media:' . $mediaId : 'text:' . $contenido;
        $contentHash = md5($hashSource);
        $eventKey = sprintf('outbound:%d:%s', $ticketId, $contentHash);

        // Verifica la idempotencia: retorna antes si ya se procesó (no hace falta reintentar)
        $existingEvent = $this->eventoProcesadoRepository->findByEventoId($eventKey);
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

        // Lógica de reintento para el bloqueo optimista
        $maxRetries = 3;
        $attempt = 0;
        $ticket = null;
        $result = null;

        while ($attempt < $maxRetries) {
            try {
                $result = $this->executeTransactionWithRetry($ticketId, $contenido, $eventKey, $agenteId, $ticket, $metadatosDeAdjunto, $remitenteEsPrivilegiado);
                break;
            } catch (OptimisticLockException $e) {
                // Un intento revertido no debe filtrar los eventos que recolectó
                // (p. ej. TicketAssigned) al flush del siguiente intento;
                // si no, el reintento vuelve a recolectar los mismos eventos
                // y produce un evento fantasma con un seq duplicado
                // (design.md D6).
                $this->recolectorDeEventos->limpiar();

                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw new ConcurrencyException(
                        'Max retries exceeded for optimistic locking on ticket ' . $ticketId,
                        $e
                    );
                }

                // Backoff exponencial: 100ms, 200ms, 400ms
                $sleepMs = $attempt * $attempt * 100;
                usleep($sleepMs * 1000);

                // Refresca el estado del ticket antes de reintentar
                if ($ticket !== null) {
                    $this->em->refresh($ticket);
                }

                // Limpia el entity manager para evitar estado obsoleto
                $this->em->clear();
            } catch (\Throwable $e) {
                // Cualquier otro aborto (p. ej. ConflictoDeEstadoException,
                // VentanaVencidaException) también debe descartar
                // lo que este intento haya recolectado antes de propagarse:
                // estos no se reintentan, pero el recolector no debe
                // arrastrar eventos obsoletos a una llamada posterior no relacionada
                // (design.md D6).
                $this->recolectorDeEventos->limpiar();
                throw $e;
            }
        }

        if ($result === null) {
            // Nunca debería llegar aquí, pero satisface el análisis estático
            throw new ConcurrencyException('Unexpected error in retry loop');
        }

        // Persiste los eventos DESPUÉS de que la transacción termina de confirmarse (se sacó
        // del bloque try, igual que AsignarTicketUseCase:118-121 /
        // AsignarTicketAutomaticamenteUseCase:55-62): con el flush del outbox dentro
        // del try, una OptimisticLockException en ese flush volvía a ejecutar
        // TODA la transacción y recolectaba eventos duplicados
        // (design.md D6).
        $this->persistirEventosEnOutbox();

        return $result;
    }

    /**
     * Ejecuta un intento del flujo saliente dentro de una transacción. $ticket se
     * asigna apenas se bloquea la fila, para que el bucle de reintentos pueda refrescarlo.
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
            // Obtiene el ticket: lectura con bloqueo pesimista como PRIMERA sentencia de
            // la transacción (design.md D1). Con REPEATABLE READ de MySQL, un
            // findById() simple abre un snapshot de lectura consistente ANTES de
            // adquirir cualquier bloqueo; un refresh() posterior seguiría leyendo ese
            // snapshot obsoleto. Una lectura con bloqueo aquí espera hasta que cualquier
            // transacción en conflicto se confirme, y luego HINT_REFRESH hidrata
            // desde la última fila confirmada.
            $ticket = $this->ticketRepo->findByIdForUpdate($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found');
            }

            $omitirCambioDeResponsable = TicketResponsabilidadGuard::isResueltoOCerrado($ticket->getEstado());
            $this->verificarEnvio($ticket, $ticketId, $agenteId, $remitenteEsPrivilegiado);

            // Obtiene la conversación
            $conversacion = $ticket->getConversacion();
            if (!$conversacion) {
                throw new \InvalidArgumentException('Ticket has no conversation');
            }

            // Se omite en tickets resueltos/cerrados: allí el responsable queda congelado.
            if (!$omitirCambioDeResponsable) {
                $this->tomarTicketParaAgenteQueResponde($ticket, $agenteId);
            }

            $mensaje = $this->createMensajeSaliente($conversacion, $contenido, $agenteId, $metadatosDeAdjunto);

            [$estadoActual, $nuevoEstado, $transicion, $debeRegistrarPrimeraRespuesta] = $this->aplicarTransicionDeRespuesta($ticket, $ticketId);

            // Actualiza las marcas de tiempo de la conversación
            $conversacion->setUltimoMensajeAt($mensaje->getCreadoAt());
            $conversacion->setActualizadoAt(new \DateTimeImmutable());
            $ticket->marcarActualizado();

            // Guarda el evento procesado para la idempotencia
            $resultData = [
                'ticket_id' => $ticketId,
                'message_id' => $mensaje->getId(),
                'previous_status' => $estadoActual,
                'new_status' => $nuevoEstado,
                'first_response_set' => $debeRegistrarPrimeraRespuesta,
                'transition' => $transicion ?? 'none',
            ];
            $eventoProcesado = new EventoProcesado($eventKey);
            $eventoProcesado->setDatosDeResultado($resultData);
            $this->em->persist($eventoProcesado);

            $this->em->flush();

            $this->registrarMessageCreatedEvent($ticket, $ticketId, $mensaje, $contenido, $agenteId);

            // Vuelve a hacer flush para persistir el lastEventSeq incrementado (design.md D5).
            $this->em->flush();

            // Ejecuta las reglas de automatización (disparador saliente)
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
     * Rechaza el envío antes de persistir nada.
     *
     * Guarda de responsable en tickets resueltos/cerrados (design.md D3-D5, spec.md
     * "Resolved/Closed Ticket Ownership Guard"): un agente que no es el responsable ni
     * tiene privilegios no puede reclamar/reasignar un ticket resuelto/cerrado respondiendo. Se
     * ejecuta ANTES de la verificación de la política de ventana: un 409 aquí informa más
     * que un 422 engañoso, y el responsable de un ticket cerrado es
     * estable/autoritativo, mientras que la ventana solo depende del tiempo.
     *
     * Guarda de la ventana de conversación de 24 h de WhatsApp antes de persistir (design.md D3/D4):
     * el texto libre se rechaza una vez vencida la ventana efectiva, antes de que se
     * persista cualquier fila de mensaje y antes de cualquier transición de la FSM: nunca
     * un mensaje "sent" fantasma.
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
     * Asigna el ticket al agente que responde cuando otro (o nadie) es su
     * responsable. Un agente desconocido, o el id de agente 0, deja el ticket como está.
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

        // Hace flush de la asignación + cualquier cambio de estado hecho por ResponsabilidadDeTicketService
        // (p. ej. new → active) para que la BD quede consistente antes de decidir la
        // transición de la FSM de abajo.
        $this->em->flush();
        $this->em->refresh($ticket);

        // Cuando un agente responde y pasa a ser el responsable, se marca como leído
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

        // Adjunta los metadatos del adjunto si se enviaron
        if ($metadatosDeAdjunto !== null) {
            $mensaje->setAdjunto($metadatosDeAdjunto);
        }

        $this->em->persist($mensaje);

        return $mensaje;
    }

    /**
     * Mueve el ticket después de una respuesta del agente (new → active, active → waiting,
     * waiting se mantiene) y asigna first_response_at en la primera respuesta.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: bool} estado anterior, estado nuevo, nombre de la transición, si se asignó la primera respuesta
     */
    private function aplicarTransicionDeRespuesta(Ticket $ticket, int $ticketId): array
    {
        $estadoActual = $ticket->getEstado();
        $debeRegistrarPrimeraRespuesta = empty($ticket->getPrimeraRespuestaAt());
        $nuevoEstado = $estadoActual;
        $transicion = null;

        if ($estadoActual === EstadoDeTicket::NUEVO->value) {
            // Primera respuesta del agente: new → active
            $nuevoEstado = EstadoDeTicket::ACTIVO->value;
            $transicion = 'new_to_active';
        } elseif ($estadoActual === EstadoDeTicket::ACTIVO->value) {
            // El agente espera respuesta: active → waiting
            $nuevoEstado = EstadoDeTicket::EN_ESPERA->value;
            $transicion = 'active_to_waiting';
        } elseif ($estadoActual === EstadoDeTicket::EN_ESPERA->value) {
            // El agente responde mientras espera: sigue en waiting (el cliente podría responder)
            $nuevoEstado = EstadoDeTicket::EN_ESPERA->value;
            $transicion = 'waiting_remains';
        }

        // Ejecuta la transición si hace falta
        if ($transicion && $nuevoEstado !== $estadoActual) {
            $this->cambiarEstadoDeTicketUseCase->execute(
                $ticketId,
                $nuevoEstado,
                $debeRegistrarPrimeraRespuesta,
                false
            );
        } else if ($debeRegistrarPrimeraRespuesta) {
            // Solo asigna first_response_at, sin cambiar el estado
            $this->ticketRepo->marcarPrimeraRespuesta($ticketId);
        }

        return [$estadoActual, $nuevoEstado, $transicion, $debeRegistrarPrimeraRespuesta];
    }

    /**
     * Recolecta MessageCreatedEvent para las respuestas de agentes humanos (design.md D5), para
     * que los mensajes salientes se difundan por Mercure. El mensaje ya debe
     * tener flush, porque el evento lleva su id.
     */
    private function registrarMessageCreatedEvent(Ticket $ticket, int $ticketId, Mensaje $mensaje, string $contenido, int $agenteId): void
    {
        $nombreDelRemitente = $agenteId > 0 ? $this->agenteRepo->findById($agenteId)?->getNombre() : null;
        $this->recolectorDeEventos->recolectar(new MessageCreatedEvent(
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
     * Persiste los eventos de dominio recolectados en el outbox para el tiempo real de Mercure.
     * Después de la autoasignación, hay que publicar eventos como TicketAssigned.
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
                    // Obtiene el nombre del agente para el frontend
                    $agente = $this->agenteRepo->findById($event->ownerAgentId);
                    if ($agente !== null) {
                        $payload['assignee_name'] = $agente->getNombre();
                    }
                }
            } elseif ($event instanceof InboxUpdatedEvent) {
                // Omite por ahora los eventos de inbox (todavía no tienen formato de transmisión)
                continue;
            } elseif ($event instanceof MessageCreatedEvent) {
                $payload['message_id'] = $event->messageId;
                $payload['direction'] = $event->direction->value;
                $payload['content'] = $event->content;
                $payload['sender_name'] = $event->senderName;
                $payload['media'] = $event->media;
                // Incluye phone_number_id para el indicador de pendientes del workspace
                $phoneNumberId = $this->ticketRepo->findPhoneNumberId($event->ticketId);
                if (!empty($phoneNumberId)) {
                    $payload['phone_number_id'] = $phoneNumberId;
                }
            }

            $eventoOutbox = new EventoOutbox(
                aggregateType: 'ticket',
                aggregateId: (int) $event->ticketId,
                topic: 'tickets',
                tipoDeEvento: $event->type,
                payload: $payload
            );
            $eventosOutbox[] = $eventoOutbox;
        }

        $this->outboxWriter->write(...$eventosOutbox);
        $this->recolectorDeEventos->limpiar();
    }
}