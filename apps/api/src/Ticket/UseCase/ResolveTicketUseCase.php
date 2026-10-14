<?php

namespace App\Ticket\UseCase;

use App\Ticket\TicketStatus;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves a ticket manually (agent marks as resolved).
 */
class ResolveTicketUseCase
{
    public function __construct(
        private TransitionTicketStatusUseCase $transitionUseCase,
    ) {}

    /**
     * Resolve a ticket
     *
     * @param int $ticketId
     * @return array
     */
    public function execute(int $ticketId, string $actor = 'system'): array
    {
        return $this->transitionUseCase->resolve($ticketId, $actor);
    }
}