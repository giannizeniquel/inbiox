<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Administracion\Usuario;
use App\Agente\Agente;
use App\Agente\Repository\AgenteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Devuelve el agente del usuario y crea uno (nombre = email) cuando el usuario
 * no tiene ninguno, p. ej. un admin que envía un mensaje o cambia de espacio de trabajo.
 */
final class AsegurarAgenteDeUsuarioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgenteRepository $agenteRepository,
    ) {}

    public function execute(Usuario $usuario): Agente
    {
        $agente = $usuario->getAgente() ?? $this->agenteRepository->findOneBy(['usuario' => $usuario]);

        if ($agente === null) {
            $agente = new Agente($usuario->getEmail() ?? 'Admin');
            $agente->setUsuario($usuario);
            $this->entityManager->persist($agente);
            $this->entityManager->flush();
        }

        return $agente;
    }
}
