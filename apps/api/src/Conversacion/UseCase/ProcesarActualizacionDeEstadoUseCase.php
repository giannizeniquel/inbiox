<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Compartido\RecolectorDeEventos;
use App\Conversacion\MessageUpdatedEvent;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Conversacion\Repository\MensajeRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Procesa las entradas de estado del webhook de WhatsApp de Meta.
 *
 * Para cada entrada de estado de value.statuses[], este caso de uso:
 * 1. Busca el mensaje por WAMID (externalId)
 * 2. Valida la transición de estado (FSM solo hacia adelante)
 * 3. Actualiza el deliveryStatus del mensaje
 * 4. Publica un MessageUpdatedEvent vía Outbox → Mercure
 *
 * Diseño: AD-4 — FSM de estados solo hacia adelante, con read/failed terminales.
 */
class ProcesarActualizacionDeEstadoUseCase
{
    private const ORDEN_DE_ESTADOS = [
        'sending' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
        'failed' => 99,
    ];

    private const ESTADOS_FINALES = ['read', 'failed'];

    private const META_TO_INBIOX = [
        'sent' => 'sent',
        'delivered' => 'delivered',
        'read' => 'read',
        'failed' => 'failed',
    ];

    /** @var string|null phoneNumberId en proceso (se asigna durante procesarActualizacionDeEstado) */
    private ?string $currentPhoneNumberId = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MensajeRepository $mensajeRepo,
        private readonly TicketRepository $ticketRepo,
        private readonly RecolectorDeEventos $recolectorDeEventos,
        private readonly OutboxWriter $outboxWriter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Procesa una sola entrada de actualización de estado de un payload de webhook de Meta.
     *
     * @param array  $statusEntry La entrada de estado de value.statuses[]
     * @param string|null $phoneNumberId phone number id opcional de metadata (reservado para multinúmero)
     */
    public function procesarActualizacionDeEstado(array $statusEntry, ?string $phoneNumberId = null): void
    {
        // Se guarda para incluirlo en el payload del evento de outbox (CAP-7 / META-019)
        $this->currentPhoneNumberId = $phoneNumberId;

        // 1. Extrae los campos
        $wamid = $statusEntry['id'] ?? null;
        $nuevoEstadoCrudo = $statusEntry['status'] ?? null;
        $errors = $statusEntry['errors'] ?? [];

        if (!$wamid || !$nuevoEstadoCrudo) {
            $this->logger->warning('Status update missing id or status field', [
                'entry' => $statusEntry,
            ]);

            return;
        }

        // 2. Mapea el estado de Meta → deliveryStatus de Inbiox
        $nuevoEstado = self::META_TO_INBIOX[$nuevoEstadoCrudo] ?? null;

        if (!$nuevoEstado) {
            $this->logger->warning('Unknown Meta status value received', [
                'status' => $nuevoEstadoCrudo,
                'wamid' => $wamid,
            ]);

            return;
        }

        // 3. Busca el mensaje por WAMID
        $mensaje = $this->mensajeRepo->findByExternalId($wamid);

        if (!$mensaje) {
            $this->logger->warning('Status update received for unknown WAMID', [
                'wamid' => $wamid,
                'status' => $nuevoEstadoCrudo,
            ]);

            return;
        }

        // 4. Valida la transición (FSM solo hacia adelante, con estados terminales)
        $estadoActual = $mensaje->getEstadoDeEntrega();

        if (!$this->isTransicionValida($estadoActual, $nuevoEstado)) {
            $this->logger->info('Skipping invalid status transition', [
                'wamid' => $wamid,
                'from' => $estadoActual,
                'to' => $nuevoEstado,
            ]);

            return;
        }

        // 5. Actualiza la entidad del mensaje
        $mensaje->setEstadoDeEntrega($nuevoEstado);

        if ($nuevoEstado === 'failed' && !empty($errors)) {
            $mensaje->setMotivoDeFallo($errors[0]['title'] ?? 'Unknown error');
        }

        $mensaje->setActualizadoAt(new \DateTimeImmutable());

        // 6. Persiste la actualización del mensaje
        $this->em->flush();

        // 7. Publica MessageUpdatedEvent vía Outbox → Mercure
        $this->registrarMessageUpdatedEvent($mensaje, $nuevoEstado);
    }

    /**
     * Valida que la FSM permita una transición de estado.
     *
     * Reglas (AD-4):
     * - Los estados terminales (read, failed) NO permiten transiciones de salida
     * - 'failed' se alcanza desde CUALQUIER estado no terminal
     * - Todas las demás transiciones deben ir estrictamente hacia adelante (orden creciente)
     */
    private function isTransicionValida(string $from, string $to): bool
    {
        // Sin transiciones de salida desde estados terminales
        if (in_array($from, self::ESTADOS_FINALES, true)) {
            return false;
        }

        // Failed se alcanza desde cualquier estado no terminal
        if ($to === 'failed') {
            return true;
        }

        // Solo hacia adelante: el nuevo estado debe tener un orden mayor
        $fromOrder = self::ORDEN_DE_ESTADOS[$from] ?? -1;
        $toOrder = self::ORDEN_DE_ESTADOS[$to] ?? -1;

        return $toOrder > $fromOrder;
    }

    /**
     * Persiste un MessageUpdatedEvent en el outbox para su entrega por Mercure.
     */
    private function registrarMessageUpdatedEvent(\App\Conversacion\Mensaje $mensaje, string $nuevoEstado): void
    {
        // Resuelve el ID del ticket a través de la cadena de la conversación
        $conversacion = $mensaje->getConversacion();

        if (!$conversacion) {
            $this->logger->warning('Cannot publish MessageUpdatedEvent: message has no conversation', [
                'messageId' => $mensaje->getId(),
                'wamid' => $mensaje->getExternalId(),
            ]);

            return;
        }

        $ticket = $this->ticketRepo->findByConversacion($conversacion);

        if (!$ticket) {
            $this->logger->warning('Cannot publish MessageUpdatedEvent: conversation has no associated ticket', [
                'messageId' => $mensaje->getId(),
                'conversationId' => $conversacion->getId(),
            ]);

            return;
        }

        $ticketId = $ticket->getId();
        $seq = $ticket->incrementLastEventSeq();
        $occurredAt = new \DateTimeImmutable();

        // Recolecta el evento de dominio
        $this->recolectorDeEventos->recolectar(new MessageUpdatedEvent(
            eventId: new Ulid(),
            ticketId: $ticketId,
            occurredAt: $occurredAt,
            seq: $seq,
            version: 1,
            messageId: (int) $mensaje->getId(),
            deliveryStatus: $nuevoEstado,
            failedReason: $mensaje->getMotivoDeFallo(),
        ));

        // Persiste los eventos recolectados en la tabla del outbox
        $this->persistirEventosEnOutbox();
    }

    /**
     * Persiste los eventos de dominio recolectados por RecolectorDeEventos en la tabla EventoOutbox.
     *
     * Sigue el mismo patrón que ProcesarMensajeEntranteUseCase::persistirEventosEnOutbox().
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

            if ($event instanceof MessageUpdatedEvent) {
                $payload['message_id'] = $event->messageId;
                $payload['delivery_status'] = $event->deliveryStatus;
                $payload['failed_reason'] = $event->failedReason;
                $payload['phone_number_id'] = $this->currentPhoneNumberId;
            }

            $eventoOutbox = new EventoOutbox(
                aggregateType: 'ticket',
                aggregateId: (int) $event->ticketId,
                topic: 'tickets',
                tipoDeEvento: $event->type,
                payload: $payload,
                sequence: $event->seq,
            );

            $eventosOutbox[] = $eventoOutbox;
        }

        $this->outboxWriter->write(...$eventosOutbox);
        $this->recolectorDeEventos->limpiar();
    }
}
