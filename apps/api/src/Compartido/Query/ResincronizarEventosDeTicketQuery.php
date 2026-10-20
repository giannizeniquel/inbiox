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
        private readonly EventoOutboxRepository $eventoOutboxRepository,
        private readonly EventoEnTiempoRealFactory $factory,
    ) {}

    /**
     * @return array{events: list<array<string, mixed>>, lastSequence: int, hasMore: bool}
     */
    public function execute(int $ticketId, int $afterSequence): array
    {
        $eventos = [];
        $highest = $afterSequence;

        foreach ($this->eventoOutboxRepository->findFilasParaResincronizar($ticketId, $afterSequence) as $row) {
            $eventos[] = $this->factory->build($this->hydrateEvent($row));
            $highest = max($highest, (int) $row['sequence']);
        }

        return [
            'events' => $eventos,
            'lastSequence' => $highest,
            'hasMore' => \count($eventos) === self::PAGE_SIZE,
        ];
    }

    /**
     * Hydrate an EventoOutbox from a raw DB row.
     *
     * @param array<string, mixed> $row
     */
    private function hydrateEvent(array $row): EventoOutbox
    {
        $evento = new EventoOutbox(
            aggregateType: $row['aggregate_type'],
            aggregateId: (int) $row['aggregate_id'],
            topic: $row['topic'],
            tipoDeEvento: $row['event_type'],
            payload: json_decode($row['payload'], true) ?? [],
            sequence: (int) ($row['sequence'] ?? 0)
        );

        // Use reflection to set the ID (generated in constructor, we want the persisted one)
        $idReflection = new \ReflectionProperty(EventoOutbox::class, 'id');
        $idReflection->setAccessible(true);
        $idReflection->setValue($evento, $row['id']);

        // Set created_at
        $creadoAtReflection = new \ReflectionProperty(EventoOutbox::class, 'creadoAt');
        $creadoAtReflection->setAccessible(true);
        if (isset($row['created_at'])) {
            $creadoAtReflection->setValue($evento, new \DateTimeImmutable($row['created_at']));
        }

        // Set status
        $estadoReflection = new \ReflectionProperty(EventoOutbox::class, 'estado');
        $estadoReflection->setAccessible(true);
        $estadoReflection->setValue($evento, $row['status'] ?? 'pending');

        return $evento;
    }
}
