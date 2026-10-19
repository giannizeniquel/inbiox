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
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when user_id is invalid, the user is
     *                                   deactivated, or either side is already linked
     * @throws NotFoundException         when the user does not exist
     * @throws ConflictoDeEstadoException    when the database rejects the link as a duplicate
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

        // Q3: Deny linking deactivated user to agent
        if (!$usuario->isActivo()) {
            throw new \InvalidArgumentException('Cannot link a deactivated user to an agent');
        }

        if (null !== $agente->getUsuario()) {
            throw new \InvalidArgumentException('Agent already linked to a user');
        }

        // Check if user already has an agent (UNIQUE constraint)
        if (null !== $usuario->getAgente()) {
            throw new \InvalidArgumentException('User already linked to an agent');
        }

        $agente->setUsuario($usuario);
        $usuario->setAgente($agente);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Defensive: the checks above should prevent it.
            throw new ConflictoDeEstadoException('Link conflict: agent or user already linked', 0, $e);
        }

        return $usuario;
    }
}
