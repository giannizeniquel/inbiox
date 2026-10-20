<?php

namespace App\Compartido\Query;

use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Realtime\EventoEnTiempoRealFactory;
use App\Compartido\Repository\EventoOutboxRepository;

/**
 * Los eventos en tiempo real de un ticket posteriores a un sequence, para que el frontend
 * recupere los eventos perdidos después de una reconexión o un hueco.
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
            $eventos[] = $this->factory->build($this->hidratarEvento($row));
            $highest = max($highest, (int) $row['sequence']);
        }

        return [
            'events' => $eventos,
            'lastSequence' => $highest,
            'hasMore' => \count($eventos) === self::PAGE_SIZE,
        ];
    }

    /**
     * Hidrata un EventoOutbox a partir de una fila cruda de la base de datos.
     *
     * @param array<string, mixed> $row
     */
    private function hidratarEvento(array $row): EventoOutbox
    {
        $evento = new EventoOutbox(
            aggregateType: $row['aggregate_type'],
            aggregateId: (int) $row['aggregate_id'],
            topic: $row['topic'],
            tipoDeEvento: $row['event_type'],
            payload: json_decode($row['payload'], true) ?? [],
            sequence: (int) ($row['sequence'] ?? 0)
        );

        // Usa reflection para asignar el ID (se genera en el constructor; queremos el persistido)
        $idReflection = new \ReflectionProperty(EventoOutbox::class, 'id');
        $idReflection->setAccessible(true);
        $idReflection->setValue($evento, $row['id']);

        // Asigna created_at
        $creadoAtReflection = new \ReflectionProperty(EventoOutbox::class, 'creadoAt');
        $creadoAtReflection->setAccessible(true);
        if (isset($row['created_at'])) {
            $creadoAtReflection->setValue($evento, new \DateTimeImmutable($row['created_at']));
        }

        // Asigna el estado
        $estadoReflection = new \ReflectionProperty(EventoOutbox::class, 'estado');
        $estadoReflection->setAccessible(true);
        $estadoReflection->setValue($evento, $row['status'] ?? 'pending');

        return $evento;
    }
}
