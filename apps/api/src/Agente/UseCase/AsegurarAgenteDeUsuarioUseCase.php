<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Administracion\Usuario;
use App\Agente\Agente;
use App\Agente\Repository\AgenteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Returns the user's agent, creating one (name = email) when the user has
 * none, e.g. an admin who sends a message or switches workspace.
 */
final class AsegurarAgenteDeUsuarioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgenteRepository $agenteRepository,
    ) {}

    public function execute(Usuario $usuario): Agente
    {
        $agente = $usuario->getAgent() ?? $this->agenteRepository->findOneBy(['usuario' => $usuario]);

        if ($agente === null) {
            $agente = new Agente($usuario->getEmail() ?? 'Admin');
            $agente->setUsuario($usuario);
            $this->entityManager->persist($agente);
            $this->entityManager->flush();
        }

        return $agente;
    }
}
