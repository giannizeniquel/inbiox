<?php

declare(strict_types=1);

namespace App\Agente\Controller;

use App\Administracion\Usuario;
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
        private readonly UpdatePerfilDeAgenteUseCase $updatePerfilDeAgenteUseCase,
        private readonly UpdatePreferenciasDeNotificacionUseCase $updatePreferenciasDeNotificacionUseCase,
    ) {}

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/settings', name: 'app_me_settings', methods: ['GET'])]
    public function getConfiguracion(): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $usuario = $agente->getUsuario();

        // Profile
        $perfil = [
            'name' => $agente->getNombre(),
            'email' => $usuario?->getEmail() ?? '',
            'signature' => $agente->getFirma(),
        ];

        // Notifications
        $notificaciones = [
            'sound' => $agente->hasSonidoDeNotificacion(),
            'desktop' => $agente->hasNotificacionesDeEscritorio(),
        ];

        // AutoReply
        $respuestaAutomatica = [
            'enabled' => $agente->isRespuestaAutomaticaActiva(),
            'message' => $agente->getMensajeDeRespuestaAutomatica(),
        ];

        // Workspace
        $espacioDeTrabajoActivo = $agente->getEspacioDeTrabajoActivo();
        $datosDelEspacioDeTrabajo = null;
        if ($espacioDeTrabajoActivo !== null) {
            $datosDelEspacioDeTrabajo = [
                'id' => (int) $espacioDeTrabajoActivo->getId(),
                'displayName' => $espacioDeTrabajoActivo->getDisplayName(),
                'phoneNumberId' => $espacioDeTrabajoActivo->getPhoneNumberId(),
            ];
        }

        // Business hours (shifts)
        $horarioDeAtencion = [];
        foreach ($agente->getTurnos() as $turno) {
            $horarioDeAtencion[] = [
                'dayOfWeek' => $turno->getDiaDeLaSemana(),
                'start' => $turno->getHoraDeInicio()->format('H:i'),
                'end' => $turno->getHoraDeFin()->format('H:i'),
            ];
        }

        // Sort shifts by dayOfWeek then start
        \usort($horarioDeAtencion, function (array $a, array $b) {
            return [$a['dayOfWeek'], $a['start']] <=> [$b['dayOfWeek'], $b['start']];
        });

        return new JsonResponse([
            'profile' => $perfil,
            'notifications' => $notificaciones,
            'autoReply' => $respuestaAutomatica,
            'workspace' => $datosDelEspacioDeTrabajo,
            'businessHours' => $horarioDeAtencion,
        ]);
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/profile', name: 'app_me_profile', methods: ['PUT'])]
    public function updatePerfil(#[MapRequestPayload] UpdatePerfilRequest $request): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updatePerfilDeAgenteUseCase->execute($agente, $request->signature);

        return new JsonResponse([
            'name' => $agente->getNombre(),
            'email' => $agente->getUsuario()?->getEmail() ?? '',
            'signature' => $agente->getFirma(),
        ]);
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/notifications', name: 'app_me_notifications', methods: ['PUT'])]
    public function updateNotificaciones(#[MapRequestPayload] UpdateNotificacionesRequest $request): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updatePreferenciasDeNotificacionUseCase->execute($agente, $request->sound, $request->desktop);

        return new JsonResponse([
            'sound' => $agente->hasSonidoDeNotificacion(),
            'desktop' => $agente->hasNotificacionesDeEscritorio(),
        ]);
    }

    private function getAgente(): ?Agente
    {
        $usuario = $this->security->getUser();

        if (!$usuario instanceof Usuario) {
            return null;
        }

        return $usuario->getAgent();
    }
}
