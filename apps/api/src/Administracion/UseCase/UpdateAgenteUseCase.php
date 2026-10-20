<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateAgenteUseCase
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
    public function execute(Agente $agente, array $data): void
    {
        if (isset($data['name'])) {
            if (!\is_string($data['name']) || empty($data['name'])) {
                throw new \InvalidArgumentException('name must be a non-empty string');
            }
            // Agente no tiene setter de name. La reflexión asigna el nombre sin
            // tocar updated_at, que es el comportamiento actual.
            $propiedadNombre = new \ReflectionProperty(Agente::class, 'nombre');
            $propiedadNombre->setAccessible(true);
            $propiedadNombre->setValue($agente, $data['name']);
        }

        if (isset($data['active'])) {
            $agente->setActivo((bool) $data['active']);
        }

        if (isset($data['timezone'])) {
            if (!\is_string($data['timezone'])) {
                throw new \InvalidArgumentException('timezone must be a string');
            }
            $agente->setZonaHoraria($data['timezone']);
        }

        $this->entityManager->flush();
    }
}
