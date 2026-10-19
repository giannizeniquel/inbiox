<?php

declare(strict_types=1);

namespace App\Autenticacion\Security;

use App\Administracion\Usuario;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Validates user account status on every authentication attempt.
 *
 * checkPostAuth() runs after every successful authentication (json_login and
 * JWT alike), so an inactive user is rejected even with a still-valid token
 * (covered by AuthControllerTest::testDeactivatedUserGets403OnAuthenticatedRequest).
 */
final class VerificadorDeUsuario implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Usuario) {
            return;
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof Usuario) {
            return;
        }

        if (!$user->isActivo()) {
            $ex = new DisabledException('User account is disabled.');
            $ex->setUser($user);
            throw $ex;
        }
    }
}
