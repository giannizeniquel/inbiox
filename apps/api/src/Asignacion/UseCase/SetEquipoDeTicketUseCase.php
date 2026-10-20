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
     * Asigna o quita (null) el equipo del ticket. El espacio de trabajo del equipo debe ser
     * el del ticket (mismo phone_number_id); si no, team_id queda
     * sin cambios.
     *
     * @param mixed $equipoId el valor teamId de la solicitud
     *
     * @throws \InvalidArgumentException cuando teamId no es un entero, el
     *                                   equipo no existe o pertenece a otro espacio de trabajo
     */
    public function execute(Ticket $ticket, mixed $equipoId): void
    {
        if (null === $equipoId) {
            $ticket->setEquipo(null);
            $this->entityManager->flush();

            return;
        }

        if (!\is_int($equipoId)) {
            throw new \InvalidArgumentException('equipoId must be an integer or null');
        }

        $equipo = $this->teamRepository->find($equipoId);
        // Un equipo desconocido es un 400, no un 404 (comportamiento actual).
        if (null === $equipo) {
            throw new \InvalidArgumentException('Team not found');
        }

        if ($equipo->getEspacioDeTrabajo()->getPhoneNumberId() !== $ticket->getPhoneNumberId()) {
            throw new \InvalidArgumentException('Team does not belong to the same workspace as the ticket');
        }

        $ticket->setEquipo($equipo);
        $this->entityManager->flush();
    }
}
