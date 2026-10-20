<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\EspacioDeTrabajo;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateEspacioDeTrabajoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Aplica solo los campos presentes en $data, validándolos en orden y
     * sin guardar nada si alguno falla.
     *
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando un campo es inválido
     */
    public function execute(EspacioDeTrabajo $espacioDeTrabajo, array $data): void
    {
        if (isset($data['phoneNumberId'])) {
            if (!\is_string($data['phoneNumberId']) || empty($data['phoneNumberId'])) {
                throw new \InvalidArgumentException('phoneNumberId must be a non-empty string');
            }
            $espacioDeTrabajo->setPhoneNumberId($data['phoneNumberId']);
        }

        if (isset($data['nombreVisible'])) {
            if (!\is_string($data['nombreVisible']) || empty($data['nombreVisible'])) {
                throw new \InvalidArgumentException('nombreVisible must be a non-empty string');
            }
            $espacioDeTrabajo->setNombreVisible($data['nombreVisible']);
        }

        if (isset($data['activo'])) {
            $espacioDeTrabajo->setActivo((bool) $data['activo']);
        }

        $this->entityManager->flush();
    }
}
