<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Equipo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft delete: the team stays, with is_active = false.
 *
 * It must not clear tickets.team_id on the team's tickets (spec: Team
 * Deactivation Retains Historical Ticket Attribution).
 */
final class DeactivateTeamUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(Equipo $team): void
    {
        $team->setActivo(false);
        $this->entityManager->flush();
    }
}
