<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Borrado lógico: el usuario se conserva, con is_active = false.
 */
final class DesactivarUsuarioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function execute(Usuario $usuario): void
    {
        $usuario->setActivo(false);
        $this->entityManager->flush();
    }
}
