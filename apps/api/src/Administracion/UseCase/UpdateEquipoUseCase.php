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
        if (isset($data['nombre'])) {
            if (!\is_string($data['nombre']) || empty($data['nombre'])) {
                throw new \InvalidArgumentException('nombre must be a non-empty string');
            }

            $duplicado = $this->equipoRepository->findOneBy(['espacioDeTrabajo' => $equipo->getEspacioDeTrabajo(), 'nombre' => $data['nombre']]);
            if (null !== $duplicado && $duplicado->getId() !== $equipo->getId()) {
                throw new ConflictoDeEstadoException('A team with this name already exists in this workspace');
            }

            $equipo->setNombre($data['nombre']);
        }

        if (isset($data['activo'])) {
            $equipo->setActivo((bool) $data['activo']);
        }

        $this->entityManager->flush();
    }
}
