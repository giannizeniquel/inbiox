<?php

declare(strict_types=1);

namespace App\Compartido\Security;

use App\Administracion\Usuario;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Determines the workspace scope for the current authenticated user.
 *
 * Returns the active workspace's phoneNumberId for ROLE_AGENT users
 * with a linked Agent that has an active workspace set.
 * Returns null for SUPERVISOR, ADMIN, or AGENT without active workspace.
 *
 * This is used at the QUERY level to add WHERE clauses, filtering
 * tickets/conversations/contacts by the active workspace's phone number ID.
 */
final class AlcanceDeEspacioDeTrabajo
{
    public function __construct(
        private readonly Security $security,
    ) {}

    /**
     * Get the scoped phoneNumberId for the current user.
     *
     * ALL roles (ADMIN, SUPERVISOR, AGENT) go through the same path:
     * user → agent → activeWorkspace → phoneNumberId.
     *
     * - Usuario with linked Agent that has an active workspace: the workspace's phoneNumberId
     * - Usuario without Agent or without active workspace: null (no scope)
     * - No authenticated user: null
     *
     * The workspace switcher is responsible for setting the active workspace
     * via PUT /api/me/workspace. Admin/Supervisor can switch to any workspace;
     * Agent can only switch to assigned workspaces.
     *
     * @see MiEspacioDeTrabajoController::setEspacioDeTrabajoActivo()
     * @see AsegurarAgenteDeUsuarioUseCase — auto-creates Agent for admin/supervisor
     */
    public function getPhoneNumberIdActivo(): ?string
    {
        $usuario = $this->security->getUser();

        if (!$usuario instanceof Usuario) {
            return null;
        }

        $agente = $usuario->getAgente();

        if (null === $agente) {
            return null;
        }

        $espacioDeTrabajoActivo = $agente->getEspacioDeTrabajoActivo();

        if (null === $espacioDeTrabajoActivo) {
            return null;
        }

        return $espacioDeTrabajoActivo->getPhoneNumberId();
    }
}
