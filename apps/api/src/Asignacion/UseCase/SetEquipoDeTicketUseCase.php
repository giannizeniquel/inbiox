<?php

declare(strict_types=1);

namespace App\Asignacion\UseCase;

use App\Agente\Repository\EquipoRepository;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;

final class SetEquipoDeTicketUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EquipoRepository $teamRepository,
    ) {}

    /**
     * Sets or clears (null) the ticket's team. The team's workspace must be
     * the ticket's workspace (same phone_number_id); otherwise team_id is
     * left unchanged.
     *
     * @param mixed $equipoId the request's teamId value
     *
     * @throws \InvalidArgumentException when teamId is not an integer, the
     *                                   team does not exist, or it is in another workspace
     */
    public function execute(Ticket $ticket, mixed $equipoId): void
    {
        if (null === $equipoId) {
            $ticket->setEquipo(null);
            $this->entityManager->flush();

            return;
        }

        if (!\is_int($equipoId)) {
            throw new \InvalidArgumentException('teamId must be an integer or null');
        }

        $equipo = $this->teamRepository->find($equipoId);
        // An unknown team is a 400, not a 404 (current behavior).
        if (null === $equipo) {
            throw new \InvalidArgumentException('Team not found');
        }

        if ($equipo->getWorkspace()->getPhoneNumberId() !== $ticket->getPhoneNumberId()) {
            throw new \InvalidArgumentException('Team does not belong to the same workspace as the ticket');
        }

        $ticket->setEquipo($equipo);
        $this->entityManager->flush();
    }
}
