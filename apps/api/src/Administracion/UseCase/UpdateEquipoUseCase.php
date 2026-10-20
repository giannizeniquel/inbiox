<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Repository\EquipoRepository;
use App\Agente\Equipo;
use App\Compartido\ConflictoDeEstadoException;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateEquipoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EquipoRepository $equipoRepository,
    ) {}

    /**
     * Aplica solo los campos presentes en $data, validándolos en orden y
     * sin guardar nada si alguno falla.
     *
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando un campo es inválido
     * @throws ConflictoDeEstadoException    cuando otro equipo del espacio de trabajo tiene ese nombre
     */
    public function execute(Equipo $equipo, array $data): void
    {
        if (isset($data['name'])) {
            if (!\is_string($data['name']) || empty($data['name'])) {
                throw new \InvalidArgumentException('name must be a non-empty string');
            }

            $duplicado = $this->equipoRepository->findOneBy(['espacioDeTrabajo' => $equipo->getEspacioDeTrabajo(), 'nombre' => $data['name']]);
            if (null !== $duplicado && $duplicado->getId() !== $equipo->getId()) {
                throw new ConflictoDeEstadoException('A team with this name already exists in this workspace');
            }

            $equipo->setNombre($data['name']);
        }

        if (isset($data['isActive'])) {
            $equipo->setActivo((bool) $data['isActive']);
        }

        $this->entityManager->flush();
    }
}
