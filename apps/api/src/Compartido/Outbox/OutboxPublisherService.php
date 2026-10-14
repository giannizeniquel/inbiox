<?php

namespace App\Compartido\Outbox;

use App\Compartido\Realtime\MercureJwtService;

use App\Compartido\Realtime\RealtimeEventFactory;
use App\Compartido\Repository\OutboxEventRepository;

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
        private readonly OutboxEventRepository $outboxRepo,
        private readonly RealtimeEventFactory $factory,
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
        $events = $this->findUnpublishedEvents();

        if (empty($events)) {
            return 0;
        }

        $publishedCount = 0;
        $total = count($events);

        foreach ($events as $event) {
            $this->publishEvent($event) ? $publishedCount++ : null;
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
     * @return OutboxEvent[]
     */
    private function findUnpublishedEvents(): array
    {
        $rows = $this->outboxRepo->findPendingBatch();

        error_log('OutboxPublisher: Found ' . count($rows) . ' rows from DB');

        if (empty($rows)) {
            return [];
        }

        $events = [];
        foreach ($rows as $row) {
            $events[] = $this->hydrateEvent($row);
        }

        return $events;
    }

    /**
     * Hydrate OutboxEvent from database row.
     */
    private function hydrateEvent(array $row): OutboxEvent
    {
        // Use string ID directly - no Ulid conversion needed
        $event = new OutboxEvent(
            aggregateType: $row['aggregate_type'],
            aggregateId: (int) $row['aggregate_id'],
            topic: $row['topic'],
            eventType: $row['event_type'],
            payload: json_decode($row['payload'], true) ?? []
        );

        // Use reflection to set the ID and other fields
        $idReflection = new \ReflectionProperty(OutboxEvent::class, 'id');
        $idReflection->setAccessible(true);
        $idReflection->setValue($event, $row['id']); // ID is already a string

        // Set retry count
        $retryReflection = new \ReflectionProperty(OutboxEvent::class, 'retryCount');
        $retryReflection->setAccessible(true);
        $retryReflection->setValue($event, (int) $row['retry_count']);

        // Set created at
        $createdAtReflection = new \ReflectionProperty(OutboxEvent::class, 'createdAt');
        $createdAtReflection->setAccessible(true);
        $createdAtReflection->setValue($event, new \DateTimeImmutable($row['created_at']));

        // Set status via reflection
        $statusReflection = new \ReflectionProperty(OutboxEvent::class, 'status');
        $statusReflection->setAccessible(true);
        $statusReflection->setValue($event, $row['status'] ?? 'pending');

        // Set sequence via reflection
        $seqReflection = new \ReflectionProperty(OutboxEvent::class, 'sequence');
        $seqReflection->setAccessible(true);
        $seqReflection->setValue($event, (int) ($row['sequence'] ?? 0));

        return $event;
    }

    /**
     * Publish a single event to Mercure.
     *
     * @return bool True if published successfully
     */
    private function publishEvent(OutboxEvent $event): bool
    {
        try {
            // Generate JWT for publishing
            $token = $this->getJwtToken();
            $tokenPreview = substr($token, 0, 30) . '...';

            error_log(sprintf(
                'OutboxPublisher: Publishing event=%s topic=%s mercureUrl=%s jwt=%s',
                $event->getId(),
                $event->getTopic(),
                $this->mercureUrl,
                $tokenPreview
            ));

            // Build RealtimeEventContract v1 JSON for the wire format
            $contractData = $this->factory->build($event);
            $dataJson = json_encode($contractData);
            $postFields = http_build_query([
                'topic' => $event->getTopic(),
                'data' => $dataJson,
                'id' => $event->getId(),
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
                    $event->getId(),
                    $event->getTopic(),
                    $httpCode
                ));
                $this->markPublished($event);
                return true;
            } else {
                error_log(sprintf(
                    'OutboxPublisher: FAILURE event=%s topic=%s http=%d response=%s error=%s',
                    $event->getId(),
                    $event->getTopic(),
                    $httpCode,
                    $response ?: '(empty)',
                    $error ?: '(none)'
                ));
                $this->handleFailure($event);
                return false;
            }
        } catch (\Throwable $e) {
            error_log('OutboxPublisher: Exception publishing event=' . $event->getId() . ' error=' . $e->getMessage());
            $this->handleFailure($event);

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
    private function markPublished(OutboxEvent $event): void
    {
        $this->outboxRepo->markPublished($event->getId(), new \DateTimeImmutable());
    }

    /**
     * Handle publishing failure - increment retry count or dead-letter if exhausted.
     */
    private function handleFailure(OutboxEvent $event): void
    {
        $currentRetryCount = $event->getRetryCount();
        $newRetryCount = $currentRetryCount + 1;

        if ($newRetryCount >= self::MAX_RETRIES) {
            $this->outboxRepo->markDeadLetter($event->getId(), $newRetryCount, self::DEAD_LETTER_STATUS);

            error_log(sprintf(
                'OutboxPublisher: Event %s dead-lettered after %d retries (type=%s, aggregate_id=%d)',
                $event->getId(),
                $newRetryCount,
                $event->getEventType(),
                $event->getAggregateId()
            ));
        } else {
            $this->outboxRepo->incrementRetryCount($event->getId());
        }
    }
}