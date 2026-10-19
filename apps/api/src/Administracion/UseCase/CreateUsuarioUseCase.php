<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\UsuarioRepository;
use App\Administracion\Usuario;
use App\Agente\Repository\AgenteRepository;
use App\Compartido\NotFoundException;
use App\Compartido\StateConflictException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUsuarioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UsuarioRepository $userRepository,
        private readonly AgenteRepository $agentRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is missing or invalid
     * @throws StateConflictException    when the email or the agent is taken
     * @throws NotFoundException         when agent_id does not exist
     */
    public function execute(array $data): Usuario
    {
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $roles = $data['roles'] ?? ['ROLE_AGENT'];

        if (empty($email) || empty($password)) {
            throw new \InvalidArgumentException('email and password are required');
        }

        if (!\is_string($email) || !\is_string($password)) {
            throw new \InvalidArgumentException('email and password must be strings');
        }

        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            throw new StateConflictException('Email already exists');
        }

        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles($roles);

        // Optional agent link
        if (isset($data['agent_id']) && null !== $data['agent_id']) {
            $agent = $this->agentRepository->find($data['agent_id']);
            if (null === $agent) {
                throw new NotFoundException('Agent not found');
            }
            if (null !== $agent->getUsuario()) {
                throw new StateConflictException('Agent already linked to another user');
            }
            $agent->setUsuario($user);
            $user->setAgent($agent);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
