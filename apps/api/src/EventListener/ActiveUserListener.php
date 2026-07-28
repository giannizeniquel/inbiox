<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\User\User;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Rejects authenticated requests from inactive users with a 403 response.
 *
 * This is necessary because Lexik's JWTAuthenticator uses SelfValidatingPassport
 * which skips Symfony's UserChecker. This listener runs after authentication
 * is complete and before the controller, ensuring deactivated users cannot
 * access any endpoint even with a valid (non-expired) JWT.
 *
 * Covers: A5 — User deactivated mid-session → next request returns 403.
 */
final class ActiveUserListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (null === $token) {
            return;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new AccessDeniedException('User account is disabled.');
        }
    }
}
