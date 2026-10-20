<?php

declare(strict_types=1);

namespace App\Autenticacion\Security;

use App\Administracion\Usuario;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Valida el estado de la cuenta del usuario en cada intento de autenticación.
 *
 * checkPostAuth() se ejecuta después de cada autenticación exitosa (tanto json_login
 * como JWT), así que un usuario inactivo se rechaza aunque tenga un token todavía válido
 * (cubierto por AuthControllerTest::testDeactivatedUserGets403OnAuthenticatedRequest).
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
