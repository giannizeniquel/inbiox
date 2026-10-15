<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Repository\TeamRepository;
use App\Agente\Team;
use App\Compartido\StateConflictException;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateTeamUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamRepository $teamRepository,
    ) {}

    /**
     * Applies only the fields present in $data, checking them in order and
     * saving nothing if one fails.
     *
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is invalid
     * @throws StateConflictException    when another team in the workspace has the name
     */
    public function execute(Team $team, array $data): void
    {
        if (isset($data['name'])) {
            if (!\is_string($data['name']) || empty($data['name'])) {
                throw new \InvalidArgumentException('name must be a non-empty string');
            }

            $duplicate = $this->teamRepository->findOneBy(['workspace' => $team->getWorkspace(), 'name' => $data['name']]);
            if (null !== $duplicate && $duplicate->getId() !== $team->getId()) {
                throw new StateConflictException('A team with this name already exists in this workspace');
            }

            $team->setName($data['name']);
        }

        if (isset($data['isActive'])) {
            $team->setActive((bool) $data['isActive']);
        }

        $this->entityManager->flush();
    }
}
