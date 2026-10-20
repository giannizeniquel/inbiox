<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\UsuarioRepository;
use App\Administracion\Usuario;
use App\Agente\Agente;
use App\Compartido\NotFoundException;
use App\Compartido\ConflictoDeEstadoException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class VincularUsuarioConAgenteUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UsuarioRepository $usuarioRepository,
    ) {}

    /**
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando user_id es inválido, el usuario está
     *                                   desactivado o alguna de las partes ya está vinculada
     * @throws NotFoundException         cuando el usuario no existe
     * @throws ConflictoDeEstadoException    cuando la base de datos rechaza el vínculo por duplicado
     */
    public function execute(Agente $agente, array $data): Usuario
    {
        $usuarioId = $data['user_id'] ?? null;

        if (null === $usuarioId || !\is_int($usuarioId)) {
            throw new \InvalidArgumentException('user_id is required and must be an integer');
        }

        $usuario = $this->usuarioRepository->find($usuarioId);

        if (null === $usuario) {
            throw new NotFoundException('User not found');
        }

        // Q3: impide vincular un usuario desactivado a un agente
        if (!$usuario->isActivo()) {
            throw new \InvalidArgumentException('Cannot link a deactivated user to an agent');
        }

        if (null !== $agente->getUsuario()) {
            throw new \InvalidArgumentException('Agent already linked to a user');
        }

        // Verifica si el usuario ya tiene un agente (restricción UNIQUE)
        if (null !== $usuario->getAgente()) {
            throw new \InvalidArgumentException('User already linked to an agent');
        }

        $agente->setUsuario($usuario);
        $usuario->setAgente($agente);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Defensivo: las verificaciones anteriores deberían evitarlo.
            throw new ConflictoDeEstadoException('Link conflict: agent or user already linked', 0, $e);
        }

        return $usuario;
    }
}
