<?php

namespace App\Compartido\Query;

use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Realtime\EventoEnTiempoRealFactory;
use App\Compartido\Repository\EventoOutboxRepository;

/**
 * The realtime events of a ticket after a sequence, for the frontend to
 * recover missed events after a reconnection or a gap.
 */
final class ResincronizarEventosDeTicketQuery
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly EventoOutboxRepository $outboxEventRepository,
        private readonly EventoEnTiempoRealFactory $factory,
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
     * Hydrate an EventoOutbox from a raw DB row.
     *
     * @param array<string, mixed> $row
     */
    private function hydrateEvent(array $row): EventoOutbox
    {
        $event = new EventoOutbox(
            aggregateType: $row['aggregate_type'],
            aggregateId: (int) $row['aggregate_id'],
            topic: $row['topic'],
            eventType: $row['event_type'],
            payload: json_decode($row['payload'], true) ?? [],
            sequence: (int) ($row['sequence'] ?? 0)
        );

        // Use reflection to set the ID (generated in constructor, we want the persisted one)
        $idReflection = new \ReflectionProperty(EventoOutbox::class, 'id');
        $idReflection->setAccessible(true);
        $idReflection->setValue($event, $row['id']);

        // Set created_at
        $createdAtReflection = new \ReflectionProperty(EventoOutbox::class, 'createdAt');
        $createdAtReflection->setAccessible(true);
        if (isset($row['created_at'])) {
            $createdAtReflection->setValue($event, new \DateTimeImmutable($row['created_at']));
        }

        // Set status
        $statusReflection = new \ReflectionProperty(EventoOutbox::class, 'status');
        $statusReflection->setAccessible(true);
        $statusReflection->setValue($event, $row['status'] ?? 'pending');

        return $event;
    }
}
