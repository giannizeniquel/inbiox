<?php

namespace App\Ticket\UseCase;

use App\Ticket\EstadoDeTicket;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves a ticket manually (agent marks as resolved).
 */
class ResolverTicketUseCase
{
    public function __construct(
        private CambiarEstadoDeTicketUseCase $transitionUseCase,
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