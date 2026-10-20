<?php

namespace App\Compartido\Outbox;

use App\Compartido\Realtime\MercureJwtService;

use App\Compartido\Realtime\EventoEnTiempoRealFactory;
use App\Compartido\Repository\EventoOutboxRepository;

/**
 * Publishes unpublished outbox events to Mercure hub.
 *
 * This service is idempotent - safe to run multiple times.
 * It queries events where publishedAt IS NULL, publishes to Mercure,
 * and marks them as published on success or increments retry count on failure.
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
     * Publish all pending events to Mercure.
     *
     * @return int Number of events successfully published
     */
    public function publishPending(): int
    {
        $eventos = $this->findUnpublishedEvents();

        if (empty($eventos)) {
            return 0;
        }

        $publishedCount = 0;
        $total = count($eventos);

        foreach ($eventos as $evento) {
            $this->publishEvent($evento) ? $publishedCount++ : null;
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
     * Find unpublished events ordered by creation date.
     *
     * @return EventoOutbox[]
     */
    private function findUnpublishedEvents(): array
    {
        $rows = $this->eventoOutboxRepository->findLotePendiente();

        error_log('OutboxPublisher: Found ' . count($rows) . ' rows from DB');

        if (empty($rows)) {
            return [];
        }

        $eventos = [];
        foreach ($rows as $row) {
            $eventos[] = $this->hydrateEvent($row);
        }

        return $eventos;
    }

    /**
     * Hydrate EventoOutbox from database row.
     */
    private function hydrateEvent(array $row): EventoOutbox
    {
        // Use string ID directly - no Ulid conversion needed
        $evento = new EventoOutbox(
            aggregateType: $row['aggregate_type'],
            aggregateId: (int) $row['aggregate_id'],
            topic: $row['topic'],
            tipoDeEvento: $row['event_type'],
            payload: json_decode($row['payload'], true) ?? []
        );

        // Use reflection to set the ID and other fields
        $idReflection = new \ReflectionProperty(EventoOutbox::class, 'id');
        $idReflection->setAccessible(true);
        $idReflection->setValue($evento, $row['id']); // ID is already a string

        // Set retry count
        $reintentosReflection = new \ReflectionProperty(EventoOutbox::class, 'reintentos');
        $reintentosReflection->setAccessible(true);
        $reintentosReflection->setValue($evento, (int) $row['retry_count']);

        // Set created at
        $creadoAtReflection = new \ReflectionProperty(EventoOutbox::class, 'creadoAt');
        $creadoAtReflection->setAccessible(true);
        $creadoAtReflection->setValue($evento, new \DateTimeImmutable($row['created_at']));

        // Set status via reflection
        $estadoReflection = new \ReflectionProperty(EventoOutbox::class, 'estado');
        $estadoReflection->setAccessible(true);
        $estadoReflection->setValue($evento, $row['status'] ?? 'pending');

        // Set sequence via reflection
        $seqReflection = new \ReflectionProperty(EventoOutbox::class, 'sequence');
        $seqReflection->setAccessible(true);
        $seqReflection->setValue($evento, (int) ($row['sequence'] ?? 0));

        return $evento;
    }

    /**
     * Publish a single event to Mercure.
     *
     * @return bool True if published successfully
     */
    private function publishEvent(EventoOutbox $evento): bool
    {
        try {
            // Generate JWT for publishing
            $token = $this->getJwtToken();
            $tokenPreview = substr($token, 0, 30) . '...';

            error_log(sprintf(
                'OutboxPublisher: Publishing event=%s topic=%s mercureUrl=%s jwt=%s',
                $evento->getId(),
                $evento->getTopic(),
                $this->mercureUrl,
                $tokenPreview
            ));

            // Build RealtimeEventContract v1 JSON for the wire format
            $contractData = $this->factory->build($evento);
            $dataJson = json_encode($contractData);
            $postFields = http_build_query([
                'topic' => $evento->getTopic(),
                'data' => $dataJson,
                'id' => $evento->getId(),
                'type' => $contractData['eventType'],
            ]);

            error_log('OutboxPublisher: POST data (first 200 chars): ' . substr($postFields, 0, 200));

            // Publish directly via curl
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
                $this->handleFailure($evento);
                return false;
            }
        } catch (\Throwable $e) {
            error_log('OutboxPublisher: Exception publishing event=' . $evento->getId() . ' error=' . $e->getMessage());
            $this->handleFailure($evento);

            return false;
        }
    }

    /**
     * Get JWT token for publishing.
     */
    private function getJwtToken(): string
    {
        // Publisher token with wildcard publish permission (agent ID 0 = system publisher)
        return $this->jwtService->generateToken(0, ['*']);
    }

    /**
     * Mark event as published.
     */
    private function marcarPublicado(EventoOutbox $evento): void
    {
        $this->eventoOutboxRepository->marcarPublicado($evento->getId(), new \DateTimeImmutable());
    }

    /**
     * Handle publishing failure - increment retry count or dead-letter if exhausted.
     */
    private function handleFailure(EventoOutbox $evento): void
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