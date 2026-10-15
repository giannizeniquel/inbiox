<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Administracion\User;
use App\Agente\Agent;
use App\Agente\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Returns the user's agent, creating one (name = email) when the user has
 * none, e.g. an admin who sends a message or switches workspace.
 */
final class EnsureAgentForUserUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentRepository $agentRepository,
    ) {}

    public function execute(User $user): Agent
    {
        $agent = $user->getAgent() ?? $this->agentRepository->findOneBy(['user' => $user]);

        if ($agent === null) {
            $agent = new Agent($user->getEmail() ?? 'Admin');
            $agent->setUser($user);
            $this->entityManager->persist($agent);
            $this->entityManager->flush();
        }

        return $agent;
    }
}
