<?php

namespace App\Controller;

use App\Application\Realtime\RealtimeEventFactory;
use App\Application\Service\MercureJwtService;
use App\Domain\Outbox\OutboxEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Provides sequence-based resync of outbox events for a ticket.
 *
 * The frontend calls this after reconnection or gap detection to recover
 * missed events. JWT auth mirrors the Mercure SSE subscription model:
 * the JWT must include "ticket/{id}" in its subscribe topics.
 *
 * Response shape uses `events`/`lastSequence`/`hasMore` (NOT data/meta).
 */
final class TicketEventsController
{
    public function __construct(
        private readonly Connection $conn,
        private readonly RealtimeEventFactory $factory,
        private readonly MercureJwtService $jwtService,
    ) {}

    #[Route('/api/mercure/token', methods: ['GET'])]
    public function getToken(): JsonResponse
    {
        $token = $this->jwtService->createSubscriberToken(['tickets']);
        return new JsonResponse(['token' => $token->toString()]);
    }

    #[Route('/api/tickets/{id}/events', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listSince(int $id, Request $request): JsonResponse
    {
        // 1. Auth: validate JWT
        $token = $this->resolveJwt($request);
        if ($token === null) {
            return new JsonResponse(['error' => 'Unauthorized — missing or invalid JWT'], 401);
        }

        // 2. Validate JWT topic includes 'tickets'
        $topics = $this->jwtService->extractTopics($token);
        if ($topics === null || !\in_array('tickets', $topics, true)) {
            return new JsonResponse(['error' => 'Forbidden — JWT does not grant access to this ticket'], 403);
        }

        // 3. Validate afterSequence parameter
        $afterSequence = $request->query->getInt('afterSequence', 0);
        if ($afterSequence < -1) {
            return new JsonResponse(['error' => 'afterSequence must be >= -1'], 400);
        }

        // 4. Query outbox_events
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, aggregate_type, aggregate_id, topic, event_type, payload, created_at, published_at, retry_count, status, sequence
             FROM outbox_events
             WHERE aggregate_id = :id
               AND sequence > :seq
               AND status IN (\'pending\', \'published\')
             ORDER BY sequence ASC
             LIMIT 100',
            ['id' => $id, 'seq' => $afterSequence]
        );

        // 5. Build contract events
        $events = [];
        $highest = $afterSequence;

        foreach ($rows as $row) {
            $outboxEvent = $this->hydrateEvent($row);
            $events[] = $this->factory->build($outboxEvent);
            $highest = max($highest, (int) $row['sequence']);
        }

        return new JsonResponse([
            'events' => $events,
            'lastSequence' => $highest,
            'hasMore' => \count($events) === 100,
        ]);
    }

    /**
     * Resolve JWT from Authorization header or mercureAuthorization cookie.
     */
    private function resolveJwt(Request $request): ?\Lcobucci\JWT\Token
    {
        // Try Authorization header first
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $tokenString = substr($authHeader, 7);
            $token = $this->jwtService->verifyToken($tokenString);
            if ($token !== null) {
                return $token;
            }
        }

        // Fall back to mercureAuthorization cookie (same cookie the Mercure hub uses)
        $cookieToken = $request->cookies->get('mercureAuthorization');
        if ($cookieToken !== null) {
            $token = $this->jwtService->verifyToken($cookieToken);
            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Hydrate an OutboxEvent from a raw DB row.
     */
    private function hydrateEvent(array $row): OutboxEvent
    {
        $event = new OutboxEvent(
            aggregateType: $row['aggregate_type'],
            aggregateId: (int) $row['aggregate_id'],
            topic: $row['topic'],
            eventType: $row['event_type'],
            payload: json_decode($row['payload'], true) ?? [],
            sequence: (int) ($row['sequence'] ?? 0)
        );

        // Use reflection to set the ID (generated in constructor, we want the persisted one)
        $idReflection = new \ReflectionProperty(OutboxEvent::class, 'id');
        $idReflection->setAccessible(true);
        $idReflection->setValue($event, $row['id']);

        // Set created_at
        $createdAtReflection = new \ReflectionProperty(OutboxEvent::class, 'createdAt');
        $createdAtReflection->setAccessible(true);
        if (isset($row['created_at'])) {
            $createdAtReflection->setValue($event, new \DateTimeImmutable($row['created_at']));
        }

        // Set status
        $statusReflection = new \ReflectionProperty(OutboxEvent::class, 'status');
        $statusReflection->setAccessible(true);
        $statusReflection->setValue($event, $row['status'] ?? 'pending');

        return $event;
    }
}
