<?php

namespace App\Ticket\UseCase;

use App\Ticket\EstadoDeTicket;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resuelve un ticket manualmente (el agente lo marca como resuelto).
 */
class ResolverTicketUseCase
{
    public function __construct(
        private CambiarEstadoDeTicketUseCase $cambiarEstadoUseCase,
    ) {}

    /**
     * Resuelve un ticket
     *
     * @param int $ticketId
     * @return array
     */
    public function execute(int $ticketId, string $actor = 'system'): array
    {
        return $this->cambiarEstadoUseCase->resolver($ticketId, $actor);
    }
}