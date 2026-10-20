<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\UsuarioRepository;
use App\Administracion\Usuario;
use App\Compartido\ConflictoDeEstadoException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UpdateUsuarioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UsuarioRepository $usuarioRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * Aplica solo los campos presentes en $data, validándolos en orden y
     * sin guardar nada si alguno falla.
     *
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando un campo es inválido
     * @throws ConflictoDeEstadoException    cuando otro usuario tiene el email
     */
    public function execute(Usuario $usuario, array $data): void
    {
        if (isset($data['email'])) {
            if (!\is_string($data['email']) || empty($data['email'])) {
                throw new \InvalidArgumentException('email must be a non-empty string');
            }
            if (null !== $this->usuarioRepository->findOtroByEmail($data['email'], (int) $usuario->getId())) {
                throw new ConflictoDeEstadoException('Email already in use');
            }
            $usuario->setEmail($data['email']);
        }

        if (isset($data['password'])) {
            if (!\is_string($data['password']) || empty($data['password'])) {
                throw new \InvalidArgumentException('password must be a non-empty string');
            }
            $usuario->setPassword($this->passwordHasher->hashPassword($usuario, $data['password']));
        }

        if (isset($data['roles'])) {
            if (!\is_array($data['roles'])) {
                throw new \InvalidArgumentException('roles must be an array');
            }
            $usuario->setRoles($data['roles']);
        }

        if (isset($data['activo'])) {
            $usuario->setActivo((bool) $data['activo']);
        }

        $this->entityManager->flush();
    }
}
