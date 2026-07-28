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
 * Attribute: VIEW_REPORT
 *
 *   VIEW_REPORT
 *   -----------
 *   ADMIN        ✓
 *   SUPERVISOR   ✓
 *   AGENT        ✗ (personal metrics only via OwnershipScope query filter)
 *
 * AGENT is denied at the voter level; their data access is scoped
 * by the OwnershipScope at the query level in ReportingService.
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
