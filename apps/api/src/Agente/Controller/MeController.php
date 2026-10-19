<?php

declare(strict_types=1);

namespace App\Agente\Controller;

use App\Administracion\User;
use App\Agente\Agent;
use App\Agente\UseCase\UpdateAgentProfileUseCase;
use App\Agente\UseCase\UpdateNotificationPreferencesUseCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Current agent's settings page and preferences.
 *
 * GET  /api/me/settings        — returns all settings sections
 * PUT  /api/me/profile         — update signature
 * PUT  /api/me/notifications   — update notification preferences
 *
 * The other /api/me endpoints live in their modules: auto-reply in
 * Automatizacion, shifts in Disponibilidad, workspaces in Administracion.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MeController
{
    public function __construct(
        private readonly Security $security,
        private readonly UpdateAgentProfileUseCase $updateAgentProfileUseCase,
        private readonly UpdateNotificationPreferencesUseCase $updateNotificationPreferencesUseCase,
    ) {}

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/settings', name: 'app_me_settings', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
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
                'dayOfWeek' => $shift->getDiaDeLaSemana(),
                'start' => $shift->getHoraDeInicio()->format('H:i'),
                'end' => $shift->getHoraDeFin()->format('H:i'),
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
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/profile', name: 'app_me_profile', methods: ['PUT'])]
    public function updateProfile(#[MapRequestPayload] UpdateProfileRequest $request): JsonResponse
    {
        $agent = $this->getAgent();
        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updateAgentProfileUseCase->execute($agent, $request->signature);

        return new JsonResponse([
            'name' => $agent->getName(),
            'email' => $agent->getUser()?->getEmail() ?? '',
            'signature' => $agent->getSignature(),
        ]);
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/notifications', name: 'app_me_notifications', methods: ['PUT'])]
    public function updateNotifications(#[MapRequestPayload] UpdateNotificationsRequest $request): JsonResponse
    {
        $agent = $this->getAgent();
        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updateNotificationPreferencesUseCase->execute($agent, $request->sound, $request->desktop);

        return new JsonResponse([
            'sound' => $agent->hasNotificationSound(),
            'desktop' => $agent->hasDesktopNotifications(),
        ]);
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
