<?php

namespace App\Compartido\Outbox;

use App\Compartido\Realtime\MercureJwtService;

use App\Compartido\Realtime\EventoEnTiempoRealFactory;
use App\Compartido\Repository\EventoOutboxRepository;

/**
 * Publica en el hub de Mercure los eventos del outbox no publicados.
 *
 * Este servicio es idempotente: se puede ejecutar varias veces sin riesgo.
 * Consulta los eventos con publishedAt IS NULL, los publica en Mercure
 * y los marca como publicados si tiene éxito o incrementa el contador de reintentos si falla.
 */
class OutboxPublisherService
{
    private const MAX_RETRIES = 5;
    private const DEAD_LETTER_STATUS = 'dead_letter';
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EventoOutboxRepository $eventoOutboxRepository,
        private readonly EventoEnTiempoRealFactory $factory,
        private readonly MercureJwtService $jwtService,
        private readonly string $mercureUrl = 'http://mercure:80/.well-known/mercure'
    ) {}

    /**
     * Publica en Mercure todos los eventos pendientes.
     *
     * @return int Cantidad de eventos publicados con éxito
     */
    public function publishPending(): int
    {
        $eventos = $this->findEventosNoPublicados();

        if (empty($eventos)) {
            return 0;
        }

        $publishedCount = 0;
        $total = count($eventos);

        foreach ($eventos as $evento) {
            $this->publicarEvento($evento) ? $publishedCount++ : null;
        }

        error_log(sprintf(
            'OutboxPublisher: publishPending complete — %d/%d published, %d failed',
            $publishedCount,
            $total,
            $total - $publishedCount
        ));

        return $publishedCount;
    }

    /**
     * Busca los eventos no publicados ordenados por fecha de creación.
     *
     * @return EventoOutbox[]
     */
    private function findEventosNoPublicados(): array
    {
        $rows = $this->eventoOutboxRepository->findLotePendiente();

        error_log('OutboxPublisher: Found ' . count($rows) . ' rows from DB');

        if (empty($rows)) {
            return [];
        }

        $eventos = [];
        foreach ($rows as $row) {
            $eventos[] = $this->hidratarEvento($row);
        }

        return $eventos;
    }

    /**
     * Hidrata un EventoOutbox a partir de una fila de la base de datos.
     */
    private function hidratarEvento(array $row): EventoOutbox
    {
        // Usa el ID string directamente - no hace falta convertirlo a Ulid
        $evento = new EventoOutbox(
            aggregateType: $row['aggregate_type'],
            aggregateId: (int) $row['aggregate_id'],
            topic: $row['topic'],
            tipoDeEvento: $row['event_type'],
            payload: json_decode($row['payload'], true) ?? []
        );

        // Usa reflection para asignar el ID y los demás campos
        $idReflection = new \ReflectionProperty(EventoOutbox::class, 'id');
        $idReflection->setAccessible(true);
        $idReflection->setValue($evento, $row['id']); // El ID ya es un string

        // Asigna el contador de reintentos
        $reintentosReflection = new \ReflectionProperty(EventoOutbox::class, 'reintentos');
        $reintentosReflection->setAccessible(true);
        $reintentosReflection->setValue($evento, (int) $row['retry_count']);

        // Asigna la fecha de creación
        $creadoAtReflection = new \ReflectionProperty(EventoOutbox::class, 'creadoAt');
        $creadoAtReflection->setAccessible(true);
        $creadoAtReflection->setValue($evento, new \DateTimeImmutable($row['created_at']));

        // Asigna el estado mediante reflection
        $estadoReflection = new \ReflectionProperty(EventoOutbox::class, 'estado');
        $estadoReflection->setAccessible(true);
        $estadoReflection->setValue($evento, $row['status'] ?? 'pending');

        // Asigna el sequence mediante reflection
        $seqReflection = new \ReflectionProperty(EventoOutbox::class, 'sequence');
        $seqReflection->setAccessible(true);
        $seqReflection->setValue($evento, (int) ($row['sequence'] ?? 0));

        return $evento;
    }

    /**
     * Publica un único evento en Mercure.
     *
     * @return bool True si se publicó con éxito
     */
    private function publicarEvento(EventoOutbox $evento): bool
    {
        try {
            // Genera el JWT para publicar
            $token = $this->getJwtToken();
            $tokenPreview = substr($token, 0, 30) . '...';

            error_log(sprintf(
                'OutboxPublisher: Publishing event=%s topic=%s mercureUrl=%s jwt=%s',
                $evento->getId(),
                $evento->getTopic(),
                $this->mercureUrl,
                $tokenPreview
            ));

            // Arma el JSON de RealtimeEventContract v1 para el formato de cable
            $contractData = $this->factory->build($evento);
            $dataJson = json_encode($contractData);
            $postFields = http_build_query([
                'topic' => $evento->getTopic(),
                'data' => $dataJson,
                'id' => $evento->getId(),
                'type' => $contractData['tipoDeEvento'],
            ]);

            error_log('OutboxPublisher: POST data (first 200 chars): ' . substr($postFields, 0, 200));

            // Publica directamente mediante curl
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->mercureUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Bearer ' . $token,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400) {
                error_log(sprintf(
                    'OutboxPublisher: SUCCESS event=%s topic=%s http=%d',
                    $evento->getId(),
                    $evento->getTopic(),
                    $httpCode
                ));
                $this->marcarPublicado($evento);
                return true;
            } else {
                error_log(sprintf(
                    'OutboxPublisher: FAILURE event=%s topic=%s http=%d response=%s error=%s',
                    $evento->getId(),
                    $evento->getTopic(),
                    $httpCode,
                    $response ?: '(empty)',
                    $error ?: '(none)'
                ));
                $this->registrarFallo($evento);
                return false;
            }
        } catch (\Throwable $e) {
            error_log('OutboxPublisher: Exception publishing event=' . $evento->getId() . ' error=' . $e->getMessage());
            $this->registrarFallo($evento);

            return false;
        }
    }

    /**
     * Obtiene el token JWT para publicar.
     */
    private function getJwtToken(): string
    {
        // Token de publicador con permiso de publicación comodín (ID de agente 0 = publicador del sistema)
        return $this->jwtService->generateToken(0, ['*']);
    }

    /**
     * Marca el evento como publicado.
     */
    private function marcarPublicado(EventoOutbox $evento): void
    {
        $this->eventoOutboxRepository->marcarPublicado($evento->getId(), new \DateTimeImmutable());
    }

    /**
     * Maneja un fallo de publicación - incrementa el contador de reintentos o lo pasa a dead letter si se agotaron.
     */
    private function registrarFallo(EventoOutbox $evento): void
    {
        $reintentosActuales = $evento->getReintentos();
        $nuevosReintentos = $reintentosActuales + 1;

        if ($nuevosReintentos >= self::MAX_RETRIES) {
            $this->eventoOutboxRepository->marcarDeadLetter($evento->getId(), $nuevosReintentos, self::DEAD_LETTER_STATUS);

            error_log(sprintf(
                'OutboxPublisher: Event %s dead-lettered after %d retries (type=%s, aggregate_id=%d)',
                $evento->getId(),
                $nuevosReintentos,
                $evento->getTipoDeEvento(),
                $evento->getAggregateId()
            ));
        } else {
            $this->eventoOutboxRepository->incrementarReintentos($evento->getId());
        }
    }
}