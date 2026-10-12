<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Team\Team;
use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Non-admin, workspace-scoped Team listing (Tier 2 #7 — Team/Area Entity,
 * Slice 1). Built now as part of Slice 1's API surface for the future
 * inbox filter dropdown (Slice 3).
 *
 * Accessible to ANY authenticated agent (ROLE_AGENT included) — not
 * admin-only, unlike Controller/Admin/TeamController. Results are scoped
 * to the requester's own workspace(s) via their linked Agent entity.
 *
 * Deactivated teams ARE included (unlike an active-assignment picker):
 * per spec (team-management, "Team Deactivation Retains Historical Ticket
 * Attribution"), a deactivated team must remain selectable in inbox/report
 * filters even though it's hidden from NEW-assignment pickers.
 */
#[IsGranted('ROLE_AGENT')]
final class TeamController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {}

    #[Route('/api/teams', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $agent = $user->getAgent();
        if (null === $agent) {
            return new JsonResponse([]);
        }

        $workspaces = $agent->getWorkspaces()->toArray();
        if ([] === $workspaces) {
            return new JsonResponse([]);
        }

        $teams = $this->entityManager->getRepository(Team::class)
            ->createQueryBuilder('t')
            ->andWhere('t.workspace IN (:workspaces)')
            ->setParameter('workspaces', $workspaces)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        $items = array_map(fn(Team $t) => [
            'id' => (int) $t->getId(),
            'workspace_id' => (int) $t->getWorkspace()->getId(),
            'name' => $t->getName(),
            'is_active' => $t->isActive(),
        ], $teams);

        return new JsonResponse($items);
    }
}
