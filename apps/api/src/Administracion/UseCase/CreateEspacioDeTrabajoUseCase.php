<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\EspacioDeTrabajoRepository;
use App\Administracion\EspacioDeTrabajo;
use App\Compartido\StateConflictException;
use Doctrine\ORM\EntityManagerInterface;

final class CreateEspacioDeTrabajoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EspacioDeTrabajoRepository $workspaceRepository,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is missing or invalid
     * @throws StateConflictException    when the phone number id is taken
     */
    public function execute(array $data): EspacioDeTrabajo
    {
        $phoneNumberId = $data['phoneNumberId'] ?? null;
        $displayName = $data['displayName'] ?? null;

        if (empty($phoneNumberId) || !\is_string($phoneNumberId)) {
            throw new \InvalidArgumentException('phoneNumberId is required and must be a string');
        }

        if (empty($displayName) || !\is_string($displayName)) {
            throw new \InvalidArgumentException('displayName is required and must be a string');
        }

        if (null !== $this->workspaceRepository->findOneBy(['phoneNumberId' => $phoneNumberId])) {
            throw new StateConflictException('A workspace with this phone number ID already exists');
        }

        $workspace = new EspacioDeTrabajo($phoneNumberId, $displayName);

        if (isset($data['isActive'])) {
            $workspace->setActivo((bool) $data['isActive']);
        }

        $this->entityManager->persist($workspace);
        $this->entityManager->flush();

        return $workspace;
    }
}
