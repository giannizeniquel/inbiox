<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\UsuarioRepository;
use App\Administracion\Usuario;
use App\Agente\Repository\AgenteRepository;
use App\Compartido\NotFoundException;
use App\Compartido\ConflictoDeEstadoException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUsuarioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UsuarioRepository $usuarioRepository,
        private readonly AgenteRepository $agenteRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando falta un campo o es inválido
     * @throws ConflictoDeEstadoException    cuando el email o el agente ya están en uso
     * @throws NotFoundException         cuando agenteId no existe
     */
    public function execute(array $data): Usuario
    {
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $roles = $data['roles'] ?? ['ROLE_AGENTE'];

        if (empty($email) || empty($password)) {
            throw new \InvalidArgumentException('email and password are required');
        }

        if (!\is_string($email) || !\is_string($password)) {
            throw new \InvalidArgumentException('email and password must be strings');
        }

        if (null !== $this->usuarioRepository->findOneBy(['email' => $email])) {
            throw new ConflictoDeEstadoException('Email already exists');
        }

        $usuario = new Usuario();
        $usuario->setEmail($email);
        $usuario->setPassword($this->passwordHasher->hashPassword($usuario, $password));
        $usuario->setRoles($roles);

        // Vínculo opcional con un agente
        if (isset($data['agenteId']) && null !== $data['agenteId']) {
            $agente = $this->agenteRepository->find($data['agenteId']);
            if (null === $agente) {
                throw new NotFoundException('Agent not found');
            }
            if (null !== $agente->getUsuario()) {
                throw new ConflictoDeEstadoException('Agent already linked to another user');
            }
            $agente->setUsuario($usuario);
            $usuario->setAgente($agente);
        }

        $this->entityManager->persist($usuario);
        $this->entityManager->flush();

        return $usuario;
    }
}
