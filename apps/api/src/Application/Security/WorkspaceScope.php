<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\User\User;
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
     * - ROLE_ADMIN / ROLE_SUPERVISOR: null (no scope — see everything)
     * - ROLE_AGENT with linked Agent and active workspace: the workspace's phoneNumberId
     * - ROLE_AGENT without active workspace: null (voter handles 403)
     * - No authenticated user: null
     */
    public function getActivePhoneNumberId(): ?string
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        // ADMIN/SUPERVISOR see everything — no scope
        if ($this->security->isGranted('ROLE_SUPERVISOR')) {
            return null;
        }

        // Only AGENT-level users get scoped
        if (!$this->security->isGranted('ROLE_AGENT')) {
            return null;
        }

        $agent = $user->getAgent();

        if (null === $agent) {
            return null;
        }

        $activeWorkspace = $agent->getActiveWorkspace();

        if (null === $activeWorkspace) {
            return null;
        }

        return $activeWorkspace->getPhoneNumberId();
    }
}
