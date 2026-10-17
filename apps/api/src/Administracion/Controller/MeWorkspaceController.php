<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\Query\WorkspaceDTO;
use App\Administracion\Repository\WorkspaceRepository;
use App\Administracion\UseCase\SetActiveWorkspaceUseCase;
use App\Administracion\User;
use App\Administracion\Workspace;
use App\Administracion\WorkspaceNotAssignedException;
use App\Compartido\NotFoundException;
use App\Ticket\Repository\TicketRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The current user's workspaces.
 *
 * GET  /api/me/workspace   — returns the active workspace or 404 if none set
 * PUT  /api/me/workspace   — sets the active workspace
 * GET  /api/me/workspaces  — returns the user's workspaces
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MeWorkspaceController
{
    public function __construct(
        private readonly Security $security,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly SetActiveWorkspaceUseCase $setActiveWorkspaceUseCase,
    ) {}

    #[Route('/api/me/workspace', name: 'app_me_workspace', methods: ['GET'])]
    public function getActiveWorkspace(): JsonResponse
    {
        $user = $this->security->getUser();
        $agent = $user instanceof User ? $user->getAgent() : null;
        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $activeWorkspace = $agent->getActiveWorkspace();
        if (null === $activeWorkspace) {
            return new JsonResponse(
                ['workspace_id' => null, 'phone_number_id' => null, 'display_name' => null],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(ActiveWorkspaceDTO::fromEntity($activeWorkspace)->toArray());
    }

    #[Route('/api/me/workspace', name: 'app_me_workspace_set', methods: ['PUT'])]
    public function setActiveWorkspace(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        // Unreachable with the entity user provider; kept as a guard.
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $workspace = $this->setActiveWorkspaceUseCase->execute(
                $user,
                $this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_SUPERVISOR'),
                \is_array($data) ? $data : [],
            );
        } catch (NotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (WorkspaceNotAssignedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(ActiveWorkspaceDTO::fromEntity($workspace)->toArray());
    }

    #[Route('/api/me/workspaces', name: 'app_me_workspaces', methods: ['GET'])]
    public function listMyWorkspaces(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Admin/Supervisor: see ALL workspaces
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_SUPERVISOR')) {
            $workspaces = $this->workspaceRepository->findBy(['isActive' => true]);
            $items = array_map(fn(Workspace $w) => WorkspaceDTO::fromEntity($w)->toArray(), $workspaces);
            return new JsonResponse($items);
        }

        // Agent: only assigned workspaces
        $agent = $user->getAgent();
        if (null === $agent) {
            return new JsonResponse([], Response::HTTP_OK);
        }

        $agentId = (int) $agent->getId();
        $items = [];
        foreach ($agent->getWorkspaces()->toArray() as $w) {
            $hasPending = $this->ticketRepository->hasPendientesDeAgente($w->getPhoneNumberId(), $agentId);
            $items[] = array_merge(WorkspaceDTO::fromEntity($w)->toArray(), ['has_pending' => $hasPending]);
        }

        return new JsonResponse($items);
    }
}
