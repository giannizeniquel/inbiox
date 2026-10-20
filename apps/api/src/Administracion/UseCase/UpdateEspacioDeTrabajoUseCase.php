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

        if (isset($data['displayName'])) {
            if (!\is_string($data['displayName']) || empty($data['displayName'])) {
                throw new \InvalidArgumentException('displayName must be a non-empty string');
            }
            $espacioDeTrabajo->setNombreVisible($data['displayName']);
        }

        if (isset($data['isActive'])) {
            $espacioDeTrabajo->setActivo((bool) $data['isActive']);
        }

        $this->entityManager->flush();
    }
}
