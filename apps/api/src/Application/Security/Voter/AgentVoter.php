<?php

declare(strict_types=1);

namespace App\Application\Security\Voter;

use App\Domain\Agent\Agent;
use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes actions on Agent objects.
 *
 * Attribute matrix:
 *   VIEW_AGENT | EDIT_AGENT | VIEW_AVAILABILITY
 *   ---------- | ---------- | -----------------
 *   ADMIN          ✓           ✓              ✓
 *   SUPERVISOR     ✓           ✓              ✓
 *   AGENT          ✓(own)      ✗              ✓(own)
 *
 *   AGENT can only VIEW_AGENT and VIEW_AVAILABILITY on their own Agent.
 *   AGENT cannot EDIT_AGENT or LINK any agent.
 */
final class AgentVoter extends Voter
{
    public const VIEW_AGENT = 'VIEW_AGENT';
    public const EDIT_AGENT = 'EDIT_AGENT';
    public const VIEW_AVAILABILITY = 'VIEW_AVAILABILITY';

    public function __construct(
        private readonly Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW_AGENT, self::EDIT_AGENT, self::VIEW_AVAILABILITY], true)
            && $subject instanceof Agent;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // ADMIN can do everything
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // SUPERVISOR can view and edit anything, but cannot LINK (not a voter attr here)
        if ($this->security->isGranted('ROLE_SUPERVISOR')) {
            return \in_array($attribute, [self::VIEW_AGENT, self::EDIT_AGENT, self::VIEW_AVAILABILITY], true);
        }

        // AGENT can only view own agent and own availability
        if ($this->security->isGranted('ROLE_AGENT')) {
            if (!\in_array($attribute, [self::VIEW_AGENT, self::VIEW_AVAILABILITY], true)) {
                return false;
            }

            $agent = $user->getAgent();

            if (null === $agent) {
                return false; // R1: no linked agent
            }

            /** @var Agent $target */
            $target = $subject;

            return $target->getId() === $agent->getId();
        }

        return false;
    }
}
