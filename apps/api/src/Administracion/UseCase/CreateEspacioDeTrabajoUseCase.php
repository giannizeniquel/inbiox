<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\EspacioDeTrabajoRepository;
use App\Administracion\EspacioDeTrabajo;
use App\Compartido\ConflictoDeEstadoException;
use Doctrine\ORM\EntityManagerInterface;

final class CreateEspacioDeTrabajoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EspacioDeTrabajoRepository $espacioDeTrabajoRepository,
    ) {}

    /**
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando falta un campo o es inválido
     * @throws ConflictoDeEstadoException    cuando el phone number id ya está en uso
     */
    public function execute(array $data): EspacioDeTrabajo
    {
        $phoneNumberId = $data['phoneNumberId'] ?? null;
        $nombreVisible = $data['displayName'] ?? null;

        if (empty($phoneNumberId) || !\is_string($phoneNumberId)) {
            throw new \InvalidArgumentException('phoneNumberId is required and must be a string');
        }

        if (empty($nombreVisible) || !\is_string($nombreVisible)) {
            throw new \InvalidArgumentException('displayName is required and must be a string');
        }

        if (null !== $this->espacioDeTrabajoRepository->findOneBy(['phoneNumberId' => $phoneNumberId])) {
            throw new ConflictoDeEstadoException('A workspace with this phone number ID already exists');
        }

        $espacioDeTrabajo = new EspacioDeTrabajo($phoneNumberId, $nombreVisible);

        if (isset($data['isActive'])) {
            $espacioDeTrabajo->setActivo((bool) $data['isActive']);
        }

        $this->entityManager->persist($espacioDeTrabajo);
        $this->entityManager->flush();

        return $espacioDeTrabajo;
    }
}
