<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Repository\EquipoRepository;
use App\Agente\Equipo;
use App\Compartido\StateConflictException;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateEquipoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EquipoRepository $equipoRepository,
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
    public function execute(Equipo $equipo, array $data): void
    {
        if (isset($data['name'])) {
            if (!\is_string($data['name']) || empty($data['name'])) {
                throw new \InvalidArgumentException('name must be a non-empty string');
            }

            $duplicado = $this->equipoRepository->findOneBy(['espacioDeTrabajo' => $equipo->getEspacioDeTrabajo(), 'nombre' => $data['name']]);
            if (null !== $duplicado && $duplicado->getId() !== $equipo->getId()) {
                throw new StateConflictException('A team with this name already exists in this workspace');
            }

            $equipo->setNombre($data['name']);
        }

        if (isset($data['isActive'])) {
            $equipo->setActivo((bool) $data['isActive']);
        }

        $this->entityManager->flush();
    }
}
