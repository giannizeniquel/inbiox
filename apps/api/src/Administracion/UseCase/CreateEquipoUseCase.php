<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\EspacioDeTrabajoRepository;
use App\Agente\Repository\EquipoRepository;
use App\Agente\Equipo;
use App\Compartido\StateConflictException;
use Doctrine\ORM\EntityManagerInterface;

final class CreateEquipoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EspacioDeTrabajoRepository $espacioDeTrabajoRepository,
        private readonly EquipoRepository $equipoRepository,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is missing or invalid, or the workspace does not exist
     * @throws StateConflictException    when the workspace already has a team with that name
     */
    public function execute(array $data): Equipo
    {
        $espacioDeTrabajoId = $data['workspaceId'] ?? null;
        $nombre = $data['name'] ?? null;

        if (empty($espacioDeTrabajoId) || !\is_int($espacioDeTrabajoId)) {
            throw new \InvalidArgumentException('workspaceId is required and must be an integer');
        }

        if (empty($nombre) || !\is_string($nombre)) {
            throw new \InvalidArgumentException('name is required and must be a string');
        }

        $espacioDeTrabajo = $this->espacioDeTrabajoRepository->find($espacioDeTrabajoId);

        // An unknown workspace is a 400, not a 404 (current behavior).
        if (null === $espacioDeTrabajo) {
            throw new \InvalidArgumentException('Workspace not found');
        }

        if (null !== $this->equipoRepository->findOneBy(['espacioDeTrabajo' => $espacioDeTrabajo, 'nombre' => $nombre])) {
            throw new StateConflictException('A team with this name already exists in this workspace');
        }

        $equipo = new Equipo($espacioDeTrabajo, $nombre);

        if (isset($data['isActive'])) {
            $equipo->setActivo((bool) $data['isActive']);
        }

        $this->entityManager->persist($equipo);
        $this->entityManager->flush();

        return $equipo;
    }
}
