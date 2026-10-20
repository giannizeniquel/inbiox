<?php

declare(strict_types=1);

namespace App\Ticket\UseCase;

use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Compartido\Realtime\TipoDeEventoEnTiempoReal;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;

final class MarcarTicketLeidoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Solo el agente asignado marca el ticket como leído. Otros usuarios pueden verlo
     * sin afectar el estado de lectura, así que para ellos esto no hace nada.
     */
    public function execute(Ticket $ticket, ?int $agenteActualId): void
    {
        $ownerAgentId = $ticket->getAgenteAsignadoId();

        if ($agenteActualId === null || $ownerAgentId === null || $agenteActualId !== $ownerAgentId) {
            return;
        }

        $ticket->setUltimaLecturaAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Emite un evento en tiempo real para que los otros clientes limpien su indicador de no leídos
        $ticketId = (int) $ticket->getId();
        $this->outboxWriter->write(new EventoOutbox(
            aggregateType: 'ticket',
            aggregateId: $ticketId,
            topic: 'tickets',
            tipoDeEvento: TipoDeEventoDeOutbox::TICKET_LEIDO->value,
            payload: [
                'eventType' => TipoDeEventoEnTiempoReal::TICKET_LEIDO->value,
                'ticketId' => $ticketId,
            ],
        ));
    }
}
