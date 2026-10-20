<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Equipo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Borrado lógico: el equipo se conserva, con is_active = false.
 *
 * No debe limpiar tickets.team_id en los tickets del equipo (spec: Team
 * Deactivation Retains Historical Ticket Attribution).
 */
final class DesactivarEquipoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(Equipo $equipo): void
    {
        $equipo->setActivo(false);
        $this->entityManager->flush();
    }
}
