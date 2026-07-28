<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\User;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Validates user account status on every authentication attempt.
 *
 * For json_login (login): called during credentials validation → 401 if inactive.
 * For JWT/refresh_jwt: NOT called (SelfValidatingPassport skips user checker),
 * so the ActiveUserListener handles inactive user rejection → 403.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            $ex = new DisabledException('User account is disabled.');
            $ex->setUser($user);
            throw $ex;
        }
    }
}
