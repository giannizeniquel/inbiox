<?php

declare(strict_types=1);

namespace App\Compartido\Security;

use App\Administracion\User;
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
final class WorkspaceScope
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
     * - User with linked Agent that has an active workspace: the workspace's phoneNumberId
     * - User without Agent or without active workspace: null (no scope)
     * - No authenticated user: null
     *
     * The workspace switcher is responsible for setting the active workspace
     * via PUT /api/me/workspace. Admin/Supervisor can switch to any workspace;
     * Agent can only switch to assigned workspaces.
     *
     * @see MeController::setActiveWorkspace()
     * @see MeController::ensureAgent() — auto-creates Agent for admin/supervisor
     */
    public function getActivePhoneNumberId(): ?string
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        $agent = $user->getAgent();

        if (null === $agent) {
            return null;
        }

        $activeWorkspace = $agent->getEspacioDeTrabajoActivo();

        if (null === $activeWorkspace) {
            return null;
        }

        return $activeWorkspace->getPhoneNumberId();
    }
}
