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
     * Applies only the fields present in $data, checking them in order and
     * saving nothing if one fails.
     *
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is invalid
     */
    public function execute(Agente $agente, array $data): void
    {
        if (isset($data['name'])) {
            if (!\is_string($data['name']) || empty($data['name'])) {
                throw new \InvalidArgumentException('name must be a non-empty string');
            }
            // Agent has no name setter. Reflection sets the name without
            // touching updated_at, which is the current behavior.
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
