<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Administracion\User;
use App\Agente\Agente;
use App\Agente\Repository\AgenteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Returns the user's agent, creating one (name = email) when the user has
 * none, e.g. an admin who sends a message or switches workspace.
 */
final class EnsureAgentForUserUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgenteRepository $agentRepository,
    ) {}

    public function execute(User $user): Agente
    {
        $agent = $user->getAgent() ?? $this->agentRepository->findOneBy(['usuario' => $user]);

        if ($agent === null) {
            $agent = new Agente($user->getEmail() ?? 'Admin');
            $agent->setUsuario($user);
            $this->entityManager->persist($agent);
            $this->entityManager->flush();
        }

        return $agent;
    }
}
