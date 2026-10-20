<?php

namespace App\Conversacion\UseCase;

use App\Conversacion\DireccionDeMensaje;
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
        private RecolectorDeEventos $recolectorDeEventos,
        private readonly OutboxWriter $outboxWriter,
        private RespuestaAutomaticaService $respuestaAutomaticaService,
        private LoggerInterface $logger,
        private readonly ?DescargaDeAdjuntoService $descargaDeAdjuntoService = null,
    ) {}

    /**
     * Procesa un mensaje entrante de un webhook o de una llamada a la API.
     *
     * @param string      $telefono           Número de teléfono del remitente en formato E.164
     * @param string      $texto         Contenido del mensaje
     * @param string|null $externalId      WAMID de Meta (para la idempotencia)
     * @param string|null $phoneNumberId   phone number id de WhatsApp que recibió este mensaje (CAP-7)
     * @param string|null $nombreDeContacto     Nombre de contacto opcional para asignar a contactos nuevos o existentes
     * @param array|null  $datosDeDescargaDeAdjunto  Payload opcional del adjunto: {mediaId, mimeType, filename, caption}
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
        // 1. Normaliza el teléfono: quita lo que no son dígitos y mantiene el formato tal cual (WhatsApp envía el prefijo correcto)
        $telefonoNormalizado = preg_replace('/[^0-9]/', '', $telefono);
        if (strlen($telefonoNormalizado) < 10) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        $telefonoNormalizado = '+' . $telefonoNormalizado;

        $result = $this->em->getConnection()->transactional(function() use ($telefonoNormalizado, $texto, $externalId, $phoneNumberId, $nombreDeContacto, $datosDeDescargaDeAdjunto) {
            return $this->executeInternal($telefonoNormalizado, $texto, $externalId, $phoneNumberId, $nombreDeContacto, $datosDeDescargaDeAdjunto);
        });

        // Después del commit, intenta la respuesta automática (fire-and-forget)
        // Se ejecuta ANTES de persistirEventosEnOutbox para mantener un orden determinista
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

        // Después del commit, persiste los eventos recolectados en el outbox
        $this->persistirEventosEnOutbox();

        return $result;
    }

    /**
     * Persiste los eventos de dominio recolectados en la tabla del outbox
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

            // Agrega los campos propios de cada evento
            if ($event instanceof MessageCreatedEvent) {
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
            } elseif ($event instanceof TicketCreatedEvent) {
                $payload['status'] = $event->status->value;
                $payload['priority'] = $event->priority;
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

    /**
     * Se ejecuta dentro de la transacción entrante. Los pasos mantienen su orden
     * original: tres flush (mensaje, adjunto, todo lo demás), la descarga del
     * adjunto desde Graph antes del bloqueo del ticket, y los incrementos de la
     * secuencia de eventos después del último flush.
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

        // Idempotencia por WAMID. Se ejecuta después de que el contacto y la conversación
        // pudieron haberse creado, así que un duplicado igual los guarda.
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
            // Actualiza el nombre del contacto existente si faltaba
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

    /** Persiste el mensaje y hace flush (primer flush), para que tenga id para la clave del adjunto. */
    private function guardarMensajeEntrante(Conversacion $conversacion, string $texto, ?string $externalId): Mensaje
    {
        $mensaje = new Mensaje();
        $mensaje->setConversacion($conversacion);
        $mensaje->setDireccion(DireccionDeMensaje::ENTRANTE->value);
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
     * Descarga el adjunto desde Graph y lo guarda mientras la transacción
     * entrante está abierta (contradiction track, S10). Un fallo solo se registra en el log:
     * el mensaje queda guardado sin adjunto. Si sale bien, hace flush (segundo flush).
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

            // Determina el tenant y el ticket para la clave del objeto
            $tenantId = 'default'; // Prefijo de tenant por defecto
            $ticketId = $mensaje->getId(); // Usa el ID del mensaje como parte de la clave

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
            // Continúa sin adjunto: el mensaje ya está persistido
        }
    }

    /**
     * Usa el ticket no cerrado del contacto (incluidos los resueltos, para la
     * reapertura automática); si no, reabre su ticket cerrado por vencimiento de ventana
     * en la misma conversación; si no, crea uno nuevo. Un ticket existente se bloquea.
     *
     * @return array{0: Ticket, 1: bool} el ticket y si es nuevo
     */
    private function findOrReabrirOrCreateTicketBloqueado(Contacto $contacto, Conversacion $conversacion, ?string $phoneNumberId): array
    {
        $ticket = $this->ticketRepo->findNoCerradoByContacto($contacto, $phoneNumberId);
        $fueReabierto = false;

        // Cumplimiento de la ventana de conversación de 24 h de WhatsApp: un ticket cerrado
        // específicamente por el proceso de vencimiento de ventana se reactiva ante un nuevo
        // mensaje entrante en lugar de generar un ticket nuevo (design.md D1).
        // Ningún endpoint de uso humano puede disparar esto; ver la guarda propia de
        // CambiarEstadoDeTicketUseCase::reopenDueToInboundMessage() y
        // TicketWorkflowControllerWindowIsolationTest.
        //
        // Limitación conocida y aceptada (proposal.md/design.md, postergada a una
        // fase futura): un ticket reabierto puede no aparecer en vivo en la
        // lista de tickets del agente, porque no se dispara ningún evento de Mercure a nivel
        // de ticket para él: `TicketUpdatedEvent` deliberadamente NO se extiende aquí
        // (restricción fija). La reapertura es totalmente correcta en la base de datos
        // (status='active', closed_at=NULL, fila de auditoría en `ticket_events`) y
        // se ve en la siguiente carga de la lista del agente. NO agregar aquí una nueva
        // emisión de eventos para cerrar esta brecha: está fuera del alcance de
        // este cambio.
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

        // Ticket existente: se bloquea para evitar seq duplicados con concurrencia
        $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);

        // `reopenDueToInboundMessage()` escribe con SQL crudo, así que la
        // entidad gestionada obtenida arriba todavía informa el obsoleto
        // `status = 'closed'`. Sin este refresh, el paso waiting→active
        // (y el `ticket_status` devuelto) leería/informaría un
        // estado equivocado.
        if ($fueReabierto) {
            $this->em->refresh($ticket);
        }

        return [$ticket, false];
    }

    /**
     * Transición de la FSM ante una respuesta del cliente. La transición escribe con
     * SQL crudo y el ticket gestionado mantiene `waiting` (contradiction track, S10).
     */
    private function activarTicketEnEspera(Ticket $ticket): ?string
    {
        if ($ticket->getEstado() !== EstadoDeTicket::EN_ESPERA->value) {
            return null;
        }

        $this->cambiarEstadoDeTicketUseCase->execute(
            $ticket->getId(),
            EstadoDeTicket::ACTIVO->value,
            false, // no asigna first_response: es el cliente
            false  // no asigna resolved
        );

        return 'waiting_to_active';
    }

    private function actualizarFechas(Conversacion $conversacion, Contacto $contacto, Ticket $ticket, Mensaje $mensaje): void
    {
        $conversacion->setUltimoMensajeAt($mensaje->getCreadoAt());
        $conversacion->setActualizadoAt(new \DateTimeImmutable());
        $contacto->setActualizadoAt(new \DateTimeImmutable());
        $ticket->setActualizadoAt(new \DateTimeImmutable());

        // Cumplimiento de la ventana de conversación de 24 h de WhatsApp: este es el único
        // que escribe `lastInboundMessageAt` (design.md, task 3.1/3.8); debe
        // ejecutarse en cada mensaje entrante, tanto para un ticket nuevo
        // como para uno reutilizado/reabierto, y en ningún otro camino (los envíos salientes
        // y las respuestas automáticas nunca tocan este campo).
        $ticket->setUltimoMensajeEntranteAt($mensaje->getCreadoAt());
    }

    /**
     * Se recolecta después del último flush, así el ticket ya tiene id. Los incrementos
     * de la secuencia se guardan con el flush del escritor del outbox.
     */
    private function registrarEventosDeEntrada(Ticket $ticket, bool $ticketNuevo, Mensaje $mensaje, string $texto, Contacto $contacto): void
    {
        if ($ticketNuevo) {
            $this->recolectorDeEventos->recolectar(new TicketCreatedEvent(
                eventId: new Ulid(),
                ticketId: $ticket->getId(),
                occurredAt: new \DateTimeImmutable(),
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
                status: EstadoDeTicket::NUEVO,
                priority: 1 // medium = 1
            ));
        }

        $this->recolectorDeEventos->recolectar(new MessageCreatedEvent(
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
     * Reglas de automatización entrantes y luego la solicitud de autoasignación. Su
     * listener se ejecuta de forma síncrona, dentro de la transacción entrante.
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
