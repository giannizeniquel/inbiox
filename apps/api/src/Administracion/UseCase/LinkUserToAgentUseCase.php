<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\UserRepository;
use App\Administracion\User;
use App\Agente\Agent;
use App\Compartido\NotFoundException;
use App\Compartido\StateConflictException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class LinkUserToAgentUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when user_id is invalid, the user is
     *                                   deactivated, or either side is already linked
     * @throws NotFoundException         when the user does not exist
     * @throws StateConflictException    when the database rejects the link as a duplicate
     */
    public function execute(Agent $agent, array $data): User
    {
        $userId = $data['user_id'] ?? null;

        if (null === $userId || !\is_int($userId)) {
            throw new \InvalidArgumentException('user_id is required and must be an integer');
        }

        $user = $this->userRepository->find($userId);

        if (null === $user) {
            throw new NotFoundException('User not found');
        }

        // Q3: Deny linking deactivated user to agent
        if (!$user->isActive()) {
            throw new \InvalidArgumentException('Cannot link a deactivated user to an agent');
        }

        if (null !== $agent->getUser()) {
            throw new \InvalidArgumentException('Agent already linked to a user');
        }

        // Check if user already has an agent (UNIQUE constraint)
        if (null !== $user->getAgent()) {
            throw new \InvalidArgumentException('User already linked to an agent');
        }

        $agent->setUser($user);
        $user->setAgent($agent);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Defensive: the checks above should prevent it.
            throw new StateConflictException('Link conflict: agent or user already linked', 0, $e);
        }

        return $user;
    }
}
