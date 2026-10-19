<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\UsuarioRepository;
use App\Administracion\Usuario;
use App\Compartido\StateConflictException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UpdateUsuarioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UsuarioRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * Applies only the fields present in $data, checking them in order and
     * saving nothing if one fails.
     *
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is invalid
     * @throws StateConflictException    when another user has the email
     */
    public function execute(Usuario $user, array $data): void
    {
        if (isset($data['email'])) {
            if (!\is_string($data['email']) || empty($data['email'])) {
                throw new \InvalidArgumentException('email must be a non-empty string');
            }
            if (null !== $this->userRepository->findOtherByEmail($data['email'], (int) $user->getId())) {
                throw new StateConflictException('Email already in use');
            }
            $user->setEmail($data['email']);
        }

        if (isset($data['password'])) {
            if (!\is_string($data['password']) || empty($data['password'])) {
                throw new \InvalidArgumentException('password must be a non-empty string');
            }
            $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
        }

        if (isset($data['roles'])) {
            if (!\is_array($data['roles'])) {
                throw new \InvalidArgumentException('roles must be an array');
            }
            $user->setRoles($data['roles']);
        }

        if (isset($data['is_active'])) {
            $user->setIsActive((bool) $data['is_active']);
        }

        $this->entityManager->flush();
    }
}
