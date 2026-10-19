<?php

declare(strict_types=1);

namespace App\Agente\Controller;

use App\Administracion\User;
use App\Agente\Agente;
use App\Agente\UseCase\UpdatePerfilDeAgenteUseCase;
use App\Agente\UseCase\UpdatePreferenciasDeNotificacionUseCase;
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
final class MiConfiguracionController
{
    public function __construct(
        private readonly Security $security,
        private readonly UpdatePerfilDeAgenteUseCase $updateAgentProfileUseCase,
        private readonly UpdatePreferenciasDeNotificacionUseCase $updateNotificationPreferencesUseCase,
    ) {}

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/settings', name: 'app_me_settings', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $agent = $this->getAgent();
        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $user = $agent->getUsuario();

        // Profile
        $profile = [
            'name' => $agent->getNombre(),
            'email' => $user?->getEmail() ?? '',
            'signature' => $agent->getFirma(),
        ];

        // Notifications
        $notifications = [
            'sound' => $agent->hasSonidoDeNotificacion(),
            'desktop' => $agent->hasNotificacionesDeEscritorio(),
        ];

        // AutoReply
        $autoReply = [
            'enabled' => $agent->isRespuestaAutomaticaActiva(),
            'message' => $agent->getMensajeDeRespuestaAutomatica(),
        ];

        // Workspace
        $activeWorkspace = $agent->getEspacioDeTrabajoActivo();
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
        foreach ($agent->getTurnos() as $shift) {
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
    public function updateProfile(#[MapRequestPayload] UpdatePerfilRequest $request): JsonResponse
    {
        $agent = $this->getAgent();
        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updateAgentProfileUseCase->execute($agent, $request->signature);

        return new JsonResponse([
            'name' => $agent->getNombre(),
            'email' => $agent->getUsuario()?->getEmail() ?? '',
            'signature' => $agent->getFirma(),
        ]);
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/notifications', name: 'app_me_notifications', methods: ['PUT'])]
    public function updateNotifications(#[MapRequestPayload] UpdateNotificacionesRequest $request): JsonResponse
    {
        $agent = $this->getAgent();
        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updateNotificationPreferencesUseCase->execute($agent, $request->sound, $request->desktop);

        return new JsonResponse([
            'sound' => $agent->hasSonidoDeNotificacion(),
            'desktop' => $agent->hasNotificacionesDeEscritorio(),
        ]);
    }

    private function getAgent(): ?Agente
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return $user->getAgent();
    }
}
