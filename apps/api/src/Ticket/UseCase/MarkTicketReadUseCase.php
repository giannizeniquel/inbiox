<?php

declare(strict_types=1);

namespace App\Ticket\UseCase;

use App\Compartido\Repository\OutboxEventRepository;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;

final class MarkTicketReadUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OutboxEventRepository $outboxEventRepository,
    ) {}

    /**
     * Only the assigned agent marks the ticket as read. Other users can view
     * it without affecting the read state, so for them this does nothing.
     */
    public function execute(Ticket $ticket, ?int $currentAgentId): void
    {
        $ownerAgentId = $ticket->getAssignedAgentId();

        if ($currentAgentId === null || $ownerAgentId === null || $currentAgentId !== $ownerAgentId) {
            return;
        }

        $ticket->setLastReadAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Emit real-time event so other clients clear their unread badge
        $this->outboxEventRepository->insertTicketReadEvent((int) $ticket->getId());
    }
}
