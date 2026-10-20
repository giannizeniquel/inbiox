<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class CreateAgenteUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando falta un campo o es inválido
     */
    public function execute(array $data): Agente
    {
        $nombre = $data['name'] ?? null;

        if (empty($nombre) || !\is_string($nombre)) {
            throw new \InvalidArgumentException('name is required and must be a string');
        }

        $zonaHoraria = $data['timezone'] ?? 'UTC';

        if (!\is_string($zonaHoraria)) {
            throw new \InvalidArgumentException('timezone must be a string');
        }

        $agente = new Agente($nombre);
        $agente->setZonaHoraria($zonaHoraria);
        $this->entityManager->persist($agente);
        $this->entityManager->flush();

        return $agente;
    }
}
