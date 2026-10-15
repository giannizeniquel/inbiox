<?php

declare(strict_types=1);

namespace App\Autenticacion\UseCase;

use App\Administracion\User;
use App\Autenticacion\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;

final class LogoutUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Deletes the refresh token from the cookie and every refresh token of
     * the authenticated user.
     */
    public function execute(?string $refreshTokenString, ?User $user): void
    {
        $refreshTokenRepo = $this->entityManager->getRepository(RefreshToken::class);

        if (null !== $refreshTokenString) {
            $token = $refreshTokenRepo->findOneBy(['refreshToken' => $refreshTokenString]);
            if (null !== $token) {
                $this->entityManager->remove($token);
            }
        }

        if (null !== $user) {
            $userTokens = $refreshTokenRepo->findBy(['username' => $user->getUserIdentifier()]);
            foreach ($userTokens as $userToken) {
                $this->entityManager->remove($userToken);
            }
        }

        $this->entityManager->flush();
    }
}
