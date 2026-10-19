<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\WorkspaceRepository;
use App\Agente\Repository\EquipoRepository;
use App\Agente\Equipo;
use App\Compartido\StateConflictException;
use Doctrine\ORM\EntityManagerInterface;

final class CreateTeamUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly EquipoRepository $teamRepository,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is missing or invalid, or the workspace does not exist
     * @throws StateConflictException    when the workspace already has a team with that name
     */
    public function execute(array $data): Equipo
    {
        $workspaceId = $data['workspaceId'] ?? null;
        $name = $data['name'] ?? null;

        if (empty($workspaceId) || !\is_int($workspaceId)) {
            throw new \InvalidArgumentException('workspaceId is required and must be an integer');
        }

        if (empty($name) || !\is_string($name)) {
            throw new \InvalidArgumentException('name is required and must be a string');
        }

        $workspace = $this->workspaceRepository->find($workspaceId);

        // An unknown workspace is a 400, not a 404 (current behavior).
        if (null === $workspace) {
            throw new \InvalidArgumentException('Workspace not found');
        }

        if (null !== $this->teamRepository->findOneBy(['espacioDeTrabajo' => $workspace, 'nombre' => $name])) {
            throw new StateConflictException('A team with this name already exists in this workspace');
        }

        $team = new Equipo($workspace, $name);

        if (isset($data['isActive'])) {
            $team->setActivo((bool) $data['isActive']);
        }

        $this->entityManager->persist($team);
        $this->entityManager->flush();

        return $team;
    }
}
