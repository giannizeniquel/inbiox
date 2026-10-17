<?php

declare(strict_types=1);

namespace App\Ticket\UseCase;

use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Compartido\Realtime\RealtimeEventType;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;

final class MarcarTicketLeidoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Only the assigned agent marks the ticket as read. Other users can view
     * it without affecting the read state, so for them this does nothing.
     */
    public function execute(Ticket $ticket, ?int $currentAgentId): void
    {
        $ownerAgentId = $ticket->getAgenteAsignadoId();

        if ($currentAgentId === null || $ownerAgentId === null || $currentAgentId !== $ownerAgentId) {
            return;
        }

        $ticket->setUltimaLecturaAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Emit real-time event so other clients clear their unread badge
        $ticketId = (int) $ticket->getId();
        $this->outboxWriter->write(new OutboxEvent(
            aggregateType: 'ticket',
            aggregateId: $ticketId,
            topic: 'tickets',
            eventType: RealtimeEventType::TicketRead->value,
            payload: [
                'eventType' => RealtimeEventType::TicketRead->value,
                'ticketId' => $ticketId,
            ],
        ));
    }
}
