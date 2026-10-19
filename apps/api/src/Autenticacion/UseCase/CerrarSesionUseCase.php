<?php

declare(strict_types=1);

namespace App\Autenticacion\UseCase;

use App\Administracion\Usuario;
use App\Autenticacion\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;

final class CerrarSesionUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Deletes the refresh token from the cookie and every refresh token of
     * the authenticated user.
     */
    public function execute(?string $refreshTokenString, ?Usuario $usuario): void
    {
        $refreshTokenRepo = $this->entityManager->getRepository(RefreshToken::class);

        if (null !== $refreshTokenString) {
            $token = $refreshTokenRepo->findOneBy(['refreshToken' => $refreshTokenString]);
            if (null !== $token) {
                $this->entityManager->remove($token);
            }
        }

        if (null !== $usuario) {
            $tokensDeUsuario = $refreshTokenRepo->findBy(['username' => $usuario->getUserIdentifier()]);
            foreach ($tokensDeUsuario as $tokenDeUsuario) {
                $this->entityManager->remove($tokenDeUsuario);
            }
        }

        $this->entityManager->flush();
    }
}
