<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\EspacioDeTrabajoRepository;
use App\Agente\Repository\EquipoRepository;
use App\Agente\Equipo;
use App\Compartido\ConflictoDeEstadoException;
use Doctrine\ORM\EntityManagerInterface;

final class CreateEquipoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EspacioDeTrabajoRepository $espacioDeTrabajoRepository,
        private readonly EquipoRepository $equipoRepository,
    ) {}

    /**
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando falta un campo o es inválido, o el espacio de trabajo no existe
     * @throws ConflictoDeEstadoException    cuando el espacio de trabajo ya tiene un equipo con ese nombre
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

        // Un espacio de trabajo desconocido es un 400, no un 404 (comportamiento actual).
        if (null === $espacioDeTrabajo) {
            throw new \InvalidArgumentException('Workspace not found');
        }

        if (null !== $this->equipoRepository->findOneBy(['espacioDeTrabajo' => $espacioDeTrabajo, 'nombre' => $nombre])) {
            throw new ConflictoDeEstadoException('A team with this name already exists in this workspace');
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
