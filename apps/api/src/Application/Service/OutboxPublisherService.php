<?php

namespace App\Application\Service;

use App\Domain\Outbox\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;

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
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?\App\Application\Service\MercureJwtService $jwtService = null,
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

        foreach ($events as $event) {
            $this->publishEvent($event) ? $publishedCount++ : null;
        }

        return $publishedCount;
    }

    /**
     * Find unpublished events ordered by creation date.
     *
     * @return OutboxEvent[]
     */
    private function findUnpublishedEvents(): array
    {
        $conn = $this->em->getConnection();

        // Use query() directly instead of prepare() to avoid introspection issues
        $sql = '
            SELECT id, aggregate_type, aggregate_id, topic, event_type, payload, created_at, published_at, retry_count, status, sequence
            FROM outbox_events
            WHERE published_at IS NULL AND status = \'pending\'
            ORDER BY created_at ASC, id ASC
            LIMIT 100
        ';

        $rows = $conn->fetchAllAssociative($sql);

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
            error_log('OutboxPublisher: Publishing event ' . $event->getId() . ' to topic ' . $event->getTopic());
            
            // Generate JWT for publishing
            $token = $this->getJwtToken();
            
            // Publish directly via curl
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->mercureUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'topic' => $event->getTopic(),
                'data' => json_encode($event->getPayload()),
                'id' => $event->getId(),
                'type' => $event->getEventType(),
            ]));
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
                error_log('OutboxPublisher: Published successfully to ' . $event->getTopic());
                $this->markPublished($event);
                return true;
            } else {
                error_log('OutboxPublisher: HTTP ' . $httpCode . ' - ' . ($response ?: $error));
                $this->handleFailure($event);
                return false;
            }
        } catch (\Throwable $e) {
            error_log('OutboxPublisher: Error publishing: ' . $e->getMessage());
            $this->handleFailure($event);

            return false;
        }
    }

    /**
     * Get JWT token for publishing.
     */
    private function getJwtToken(): string
    {
        // Generate a publisher token with wildcard publish permission
        // Use agent ID 0 and empty ticket list for system publisher
        if ($this->jwtService) {
            return $this->jwtService->generateToken(0, ['*']);
        }
        
        // Fallback: generate manually using the secret
        $secret = getenv('MERCURE_JWT_SECRET') ?: 'supersecret';
        $payload = base64_encode(json_encode([
            'mercure' => ['publish' => ['*']],
            'exp' => time() + 3600,
        ]));
        
        // Simple JWT structure (not cryptographically valid but may work for testing)
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $signature = hash_hmac('sha256', "{$header}.{$payload}", $secret);
        $signature = base64_encode(pack('H*', $signature));
        
        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Mark event as published.
     */
    private function markPublished(OutboxEvent $event): void
    {
        $conn = $this->em->getConnection();

        $sql = '
            UPDATE outbox_events
            SET published_at = :published_at,
                status = \'published\'
            WHERE id = :id
        ';

        $conn->executeStatement($sql, [
            'published_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $event->getId(), // Already a string
        ]);
    }

    /**
     * Handle publishing failure - increment retry count.
     */
    private function handleFailure(OutboxEvent $event): void
    {
        $conn = $this->em->getConnection();

        $sql = '
            UPDATE outbox_events
            SET retry_count = retry_count + 1
            WHERE id = :id
        ';

        $conn->executeStatement($sql, [
            'id' => $event->getId(), // Already a string
        ]);
    }
}