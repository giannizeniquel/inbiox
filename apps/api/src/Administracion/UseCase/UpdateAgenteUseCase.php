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
        if (isset($data['nombre'])) {
            if (!\is_string($data['nombre']) || empty($data['nombre'])) {
                throw new \InvalidArgumentException('nombre must be a non-empty string');
            }
            // Agente no tiene setter de name. La reflexión asigna el nombre sin
            // tocar updated_at, que es el comportamiento actual.
            $propiedadNombre = new \ReflectionProperty(Agente::class, 'nombre');
            $propiedadNombre->setAccessible(true);
            $propiedadNombre->setValue($agente, $data['nombre']);
        }

        if (isset($data['activo'])) {
            $agente->setActivo((bool) $data['activo']);
        }

        if (isset($data['zonaHoraria'])) {
            if (!\is_string($data['zonaHoraria'])) {
                throw new \InvalidArgumentException('zonaHoraria must be a string');
            }
            $agente->setZonaHoraria($data['zonaHoraria']);
        }

        $this->entityManager->flush();
    }
}
