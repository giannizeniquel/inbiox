<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class DesvincularUsuarioDeAgenteUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @throws \InvalidArgumentException cuando el agente no tiene un usuario vinculado
     */
    public function execute(Agente $agente): void
    {
        $usuario = $agente->getUsuario();

        if (null === $usuario) {
            throw new \InvalidArgumentException('Agent has no linked user');
        }

        $agente->setUsuario(null);
        $usuario->setAgente(null);
        $this->entityManager->flush();
    }
}
