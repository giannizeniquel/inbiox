<?php

declare(strict_types=1);

namespace App\Asignacion\UseCase;

use App\Agente\Repository\TeamRepository;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;

final class SetTicketTeamUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamRepository $teamRepository,
    ) {}

    /**
     * Sets or clears (null) the ticket's team. The team's workspace must be
     * the ticket's workspace (same phone_number_id); otherwise team_id is
     * left unchanged.
     *
     * @param mixed $teamId the request's teamId value
     *
     * @throws \InvalidArgumentException when teamId is not an integer, the
     *                                   team does not exist, or it is in another workspace
     */
    public function execute(Ticket $ticket, mixed $teamId): void
    {
        if (null === $teamId) {
            $ticket->setTeamId(null);
            $this->entityManager->flush();

            return;
        }

        if (!\is_int($teamId)) {
            throw new \InvalidArgumentException('teamId must be an integer or null');
        }

        $team = $this->teamRepository->find($teamId);
        // An unknown team is a 400, not a 404 (current behavior).
        if (null === $team) {
            throw new \InvalidArgumentException('Team not found');
        }

        if ($team->getWorkspace()->getPhoneNumberId() !== $ticket->getPhoneNumberId()) {
            throw new \InvalidArgumentException('Team does not belong to the same workspace as the ticket');
        }

        $ticket->setTeamId($teamId);
        $this->entityManager->flush();
    }
}
