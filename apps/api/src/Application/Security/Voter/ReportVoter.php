<?php

declare(strict_types=1);

namespace App\Application\Security\Voter;

use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes access to report endpoints.
 *
 * NOT CURRENTLY USED — reserved for future use:
 *
 * This voter's `VIEW_REPORT` attribute is defined but never invoked by
 * `ReportingController` or any other caller. In practice, any
 * authenticated agent (ROLE_AGENT or higher) can access every reporting
 * endpoint via `#[IsGranted('ROLE_AGENT')]` on the controller, and
 * `OwnershipScope::getAgentId()` always returns null, so no per-agent
 * filtering is applied to report data either. This is a confirmed
 * product decision (see docs/04_sprints/sprint_16 (Production
 * Readiness).md): reports are intentionally unrestricted by agent, not
 * a bug. The logic below (AGENT denied, SUPERVISOR+/ADMIN allowed) is
 * kept as reserved/dead code in case per-role report restriction is
 * reintroduced later — do not wire it into the controller without an
 * explicit product decision to do so.
 */
final class ReportVoter extends Voter
{
    public const VIEW_REPORT = 'VIEW_REPORT';

    public function __construct(
        private readonly Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW_REPORT === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // ROLE_SUPERVISOR includes ADMIN (via role_hierarchy)
        return $this->security->isGranted('ROLE_SUPERVISOR');
    }
}
