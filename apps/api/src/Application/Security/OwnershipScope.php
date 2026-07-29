<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Determines the ownership scope for the current authenticated user.
 *
 * Returns the Agent ID for ROLE_AGENT users with a linked Agent.
 * Returns null for SUPERVISOR, ADMIN, or AGENT users without a linked Agent.
 *
 * This is used at the QUERY level to add WHERE clauses, preventing
 * data leakage and poor performance from post-query filtering.
 */
final class OwnershipScope
{
    public function __construct(
        private readonly Security $security,
    ) {}

    /**
     * Get the scoped agent ID for the current user.
     *
     * - ROLE_ADMIN / ROLE_SUPERVISOR: null (no scope — see everything)
     * - ROLE_AGENT: null (agents see all tickets in their workspace;
     *   segregation is handled by WorkspaceScope.phone_number_id)
     * - No authenticated user: null
     */
    public function getAgentId(): ?int
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        // Workspace-based model: all authenticated users see all tickets
        // in their active workspace. Segregation by workspace is handled
        // at the query level via WorkspaceScope (phone_number_id filter).
        // Agent-level ownership is no longer enforced at the query level.
        return null;
    }
}
