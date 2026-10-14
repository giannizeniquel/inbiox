<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\DTO\ActiveWorkspaceDTO;
use App\Application\DTO\UpdateAutoReplyRequest;
use App\Application\DTO\UpdateNotificationsRequest;
use App\Application\DTO\UpdateProfileRequest;
use App\Application\DTO\ReplaceShiftsRequest;
use App\Application\DTO\WorkspaceDTO;
use App\Application\UseCase\ReplaceAgentShiftsUseCase;
use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentShift;
use App\Domain\User\User;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for current user's settings and preferences.
 *
 * GET  /api/me/settings        — returns all settings sections
 * PUT  /api/me/profile         — update signature
 * PUT  /api/me/notifications   — update notification preferences
 * PUT  /api/me/auto-reply      — update auto-reply settings
 * GET  /api/me/shifts          — list current agent's shifts
 * PUT  /api/me/shifts          — replace all shifts
 * GET  /api/me/workspace       — returns the active workspace or 404 if none set
 * PUT  /api/me/workspace       — sets the active workspace
 * GET  /api/me/workspaces      — returns the agent's assigned workspaces
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MeController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        private readonly ReplaceAgentShiftsUseCase $replaceAgentShiftsUseCase,
    ) {}

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/settings', name: 'app_me_settings', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        try {
            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            $user = $agent->getUser();

            // Profile
            $profile = [
                'name' => $agent->getName(),
                'email' => $user?->getEmail() ?? '',
                'signature' => $agent->getSignature(),
            ];

            // Notifications
            $notifications = [
                'sound' => $agent->hasNotificationSound(),
                'desktop' => $agent->hasDesktopNotifications(),
            ];

            // AutoReply
            $autoReply = [
                'enabled' => $agent->isAutoReplyEnabled(),
                'message' => $agent->getAutoReplyMessage(),
            ];

            // Workspace
            $activeWorkspace = $agent->getActiveWorkspace();
            $workspaceData = null;
            if ($activeWorkspace !== null) {
                $workspaceData = [
                    'id' => (int) $activeWorkspace->getId(),
                    'displayName' => $activeWorkspace->getDisplayName(),
                    'phoneNumberId' => $activeWorkspace->getPhoneNumberId(),
                ];
            }

            // Business hours (shifts)
            $businessHours = [];
            foreach ($agent->getShifts() as $shift) {
                $businessHours[] = [
                    'dayOfWeek' => $shift->getDayOfWeek(),
                    'start' => $shift->getStartTime()->format('H:i'),
                    'end' => $shift->getEndTime()->format('H:i'),
                ];
            }

            // Sort shifts by dayOfWeek then start
            \usort($businessHours, function (array $a, array $b) {
                return [$a['dayOfWeek'], $a['start']] <=> [$b['dayOfWeek'], $b['start']];
            });

            return new JsonResponse([
                'profile' => $profile,
                'notifications' => $notifications,
                'autoReply' => $autoReply,
                'workspace' => $workspaceData,
                'businessHours' => $businessHours,
            ]);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching settings: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/profile', name: 'app_me_profile', methods: ['PUT'])]
    public function updateProfile(#[MapRequestPayload] UpdateProfileRequest $request): JsonResponse
    {
        try {
            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            $agent->setSignature($request->signature);

            $this->entityManager->flush();

            return new JsonResponse([
                'name' => $agent->getName(),
                'email' => $agent->getUser()?->getEmail() ?? '',
                'signature' => $agent->getSignature(),
            ]);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error updating profile: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/notifications', name: 'app_me_notifications', methods: ['PUT'])]
    public function updateNotifications(#[MapRequestPayload] UpdateNotificationsRequest $request): JsonResponse
    {
        try {
            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            if ($request->sound !== null) {
                $agent->setNotificationSound($request->sound);
            }
            if ($request->desktop !== null) {
                $agent->setDesktopNotifications($request->desktop);
            }

            $this->entityManager->flush();

            return new JsonResponse([
                'sound' => $agent->hasNotificationSound(),
                'desktop' => $agent->hasDesktopNotifications(),
            ]);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error updating notifications: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/auto-reply', name: 'app_me_auto_reply', methods: ['PUT'])]
    public function updateAutoReply(#[MapRequestPayload] UpdateAutoReplyRequest $request): JsonResponse
    {
        try {
            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            if ($request->enabled !== null) {
                $agent->setAutoReplyEnabled($request->enabled);
            }
            // Always set message (may be null to clear it)
            $agent->setAutoReplyMessage($request->message);

            $this->entityManager->flush();

            return new JsonResponse([
                'enabled' => $agent->isAutoReplyEnabled(),
                'message' => $agent->getAutoReplyMessage(),
            ]);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error updating auto-reply: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/shifts', name: 'app_me_shifts_list', methods: ['GET'])]
    public function getShifts(): JsonResponse
    {
        try {
            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            $shifts = [];
            foreach ($agent->getShifts() as $shift) {
                $shifts[] = [
                    'dayOfWeek' => $shift->getDayOfWeek(),
                    'start' => $shift->getStartTime()->format('H:i'),
                    'end' => $shift->getEndTime()->format('H:i'),
                ];
            }

            // Sort by dayOfWeek then start
            \usort($shifts, function (array $a, array $b) {
                return [$a['dayOfWeek'], $a['start']] <=> [$b['dayOfWeek'], $b['start']];
            });

            return new JsonResponse($shifts);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching shifts: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/shifts', name: 'app_me_shifts_replace', methods: ['PUT'])]
    public function replaceShifts(#[MapRequestPayload] ReplaceShiftsRequest $request): JsonResponse
    {
        try {
            $agent = $this->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            $agentId = (int) $agent->getId();
            $created = $this->replaceAgentShiftsUseCase->execute($agentId, $request->shifts);

            return new JsonResponse($created);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error replacing shifts: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/me/workspace', name: 'app_me_workspace', methods: ['GET'])]
    public function getActiveWorkspace(): JsonResponse
    {
        try {
            $agent = $this->getAgent();
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
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching active workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/me/workspace', name: 'app_me_workspace_set', methods: ['PUT'])]
    public function setActiveWorkspace(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
            }

            $workspaceId = $data['workspaceId'] ?? null;

            if (null === $workspaceId || !\is_int($workspaceId)) {
                return new JsonResponse(['error' => 'workspaceId is required and must be an integer'], Response::HTTP_BAD_REQUEST);
            }

            $workspace = $this->entityManager->getRepository(Workspace::class)->find($workspaceId);

            if (null === $workspace) {
                return new JsonResponse(['error' => 'Workspace not found'], Response::HTTP_NOT_FOUND);
            }

            if (!$workspace->isActive()) {
                return new JsonResponse(['error' => 'Workspace is not active'], Response::HTTP_BAD_REQUEST);
            }

            $user = $this->security->getUser();
            if (!$user instanceof User) {
                return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            // Admin/Supervisor: can switch to any workspace
            if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_SUPERVISOR')) {
                $agent = $this->ensureAgent($user);
                $agent->setActiveWorkspace($workspace);
                $this->entityManager->flush();
                return new JsonResponse(ActiveWorkspaceDTO::fromEntity($workspace)->toArray());
            }

            // Agent: must be assigned to the workspace
            $agent = $user->getAgent();
            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
            }

            if (!$agent->getWorkspaces()->contains($workspace)) {
                return new JsonResponse(['error' => 'Agent is not assigned to this workspace'], Response::HTTP_FORBIDDEN);
            }

            $agent->setActiveWorkspace($workspace);
            $this->entityManager->flush();

            return new JsonResponse(ActiveWorkspaceDTO::fromEntity($workspace)->toArray());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error setting active workspace: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    private function ensureAgent(User $user): Agent
    {
        $agent = $user->getAgent();
        if ($agent === null) {
            $agent = $this->entityManager->getRepository(Agent::class)
                ->findOneBy(['user' => $user]);
            if ($agent === null) {
                $agent = new Agent($user->getEmail() ?? 'Admin');
                $agent->setUser($user);
                $this->entityManager->persist($agent);
                $this->entityManager->flush();
            }
        }
        return $agent;
    }

    #[Route('/api/me/workspaces', name: 'app_me_workspaces', methods: ['GET'])]
    public function listMyWorkspaces(): JsonResponse
    {
        try {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            // Admin/Supervisor: see ALL workspaces
            if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_SUPERVISOR')) {
                $workspaces = $this->entityManager->getRepository(Workspace::class)->findBy(['isActive' => true]);
                $items = array_map(fn(Workspace $w) => WorkspaceDTO::fromEntity($w)->toArray(), $workspaces);
                return new JsonResponse($items);
            }

            // Agent: only assigned workspaces
            $agent = $user->getAgent();
            if (null === $agent) {
                return new JsonResponse([], Response::HTTP_OK);
            }

            $workspaces = $agent->getWorkspaces()->toArray();
            $agentId = (int) $agent->getId();

            // Compute has_pending per workspace
            $conn = $this->entityManager->getConnection();
            $items = [];
            foreach ($workspaces as $w) {
                $phoneNumberId = $w->getPhoneNumberId();
                $hasPending = (bool) $conn->executeQuery(
                    'SELECT EXISTS(
                        SELECT 1 FROM tickets t
                        JOIN conversations conv ON t.conversation_id = conv.id
                        WHERE t.phone_number_id = :phone
                        AND t.assigned_agent_id = :agentId
                        AND t.status IN (:statuses)
                        AND (
                            t.last_read_at IS NULL
                            OR EXISTS(
                                SELECT 1 FROM messages m
                                WHERE m.conversation_id = conv.id
                                AND m.direction = :inbound
                                AND m.created_at > t.last_read_at
                            )
                        )
                    ) as has_pending',
                    [
                        'phone' => $phoneNumberId,
                        'agentId' => $agentId,
                        'statuses' => ['new', 'active', 'waiting'],
                        'inbound' => 'inbound',
                    ],
                    [
                        'statuses' => \Doctrine\DBAL\ArrayParameterType::STRING,
                    ],
                )->fetchOne();

                $dto = WorkspaceDTO::fromEntity($w);
                $items[] = array_merge($dto->toArray(), ['has_pending' => $hasPending]);
            }

            return new JsonResponse($items);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error listing workspaces: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    private function getAgent(): ?Agent
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return $user->getAgent();
    }
}
