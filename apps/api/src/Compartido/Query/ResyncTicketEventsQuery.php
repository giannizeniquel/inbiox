<?php

namespace App\Compartido\Query;

use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Realtime\RealtimeEventFactory;
use App\Compartido\Repository\OutboxEventRepository;

/**
 * The realtime events of a ticket after a sequence, for the frontend to
 * recover missed events after a reconnection or a gap.
 */
final class ResyncTicketEventsQuery
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly OutboxEventRepository $outboxEventRepository,
        private readonly RealtimeEventFactory $factory,
    ) {}

    /**
     * @return array{events: list<array<string, mixed>>, lastSequence: int, hasMore: bool}
     */
    public function execute(int $ticketId, int $afterSequence): array
    {
        $events = [];
        $highest = $afterSequence;

        foreach ($this->outboxEventRepository->findResyncRows($ticketId, $afterSequence) as $row) {
            $events[] = $this->factory->build($this->hydrateEvent($row));
            $highest = max($highest, (int) $row['sequence']);
        }

        return [
            'events' => $events,
            'lastSequence' => $highest,
            'hasMore' => \count($events) === self::PAGE_SIZE,
        ];
    }

    /**
     * Hydrate an OutboxEvent from a raw DB row.
     *
     * @param array<string, mixed> $row
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
