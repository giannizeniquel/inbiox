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
 * Página de configuración y preferencias del agente actual.
 *
 * GET  /api/me/settings        — devuelve todas las secciones de configuración
 * PUT  /api/me/profile         — actualiza la firma
 * PUT  /api/me/notifications   — actualiza las preferencias de notificación
 *
 * Los demás endpoints de /api/me viven en sus módulos: la respuesta automática en
 * Automatizacion, los turnos en Disponibilidad y los espacios de trabajo en Administracion.
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

        // Perfil
        $perfil = [
            'name' => $agente->getNombre(),
            'email' => $usuario?->getEmail() ?? '',
            'signature' => $agente->getFirma(),
        ];

        // Notificaciones
        $notificaciones = [
            'sound' => $agente->hasSonidoDeNotificacion(),
            'desktop' => $agente->hasNotificacionesDeEscritorio(),
        ];

        // Respuesta automática
        $respuestaAutomatica = [
            'enabled' => $agente->isRespuestaAutomaticaActiva(),
            'message' => $agente->getMensajeDeRespuestaAutomatica(),
        ];

        // Espacio de trabajo
        $espacioDeTrabajoActivo = $agente->getEspacioDeTrabajoActivo();
        $datosDelEspacioDeTrabajo = null;
        if ($espacioDeTrabajoActivo !== null) {
            $datosDelEspacioDeTrabajo = [
                'id' => (int) $espacioDeTrabajoActivo->getId(),
                'displayName' => $espacioDeTrabajoActivo->getNombreVisible(),
                'phoneNumberId' => $espacioDeTrabajoActivo->getPhoneNumberId(),
            ];
        }

        // Horario laboral (turnos)
        $horarioDeAtencion = [];
        foreach ($agente->getTurnos() as $turno) {
            $horarioDeAtencion[] = [
                'dayOfWeek' => $turno->getDiaDeLaSemana(),
                'start' => $turno->getHoraDeInicio()->format('H:i'),
                'end' => $turno->getHoraDeFin()->format('H:i'),
            ];
        }

        // Ordenar los turnos por dayOfWeek y luego por start
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

        return $usuario->getAgente();
    }
}
