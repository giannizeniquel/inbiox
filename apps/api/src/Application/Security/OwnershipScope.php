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
     * - ROLE_AGENT with linked Agent: the Agent's ID
     * - ROLE_AGENT without linked Agent: null (R1 safety; voter handles 403)
     * - No authenticated user: null
     */
    public function getAgentId(): ?int
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
            return null; // R1: no linked agent → no scope, voter will deny
        }

        return $agent->getId();
    }
}
