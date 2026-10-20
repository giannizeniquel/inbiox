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
 * GET  /api/mi/configuracion    — devuelve todas las secciones de configuración
 * PUT  /api/mi/perfil           — actualiza la firma
 * PUT  /api/mi/notificaciones   — actualiza las preferencias de notificación
 *
 * Los demás endpoints de /api/mi viven en sus módulos: la respuesta automática en
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

    #[IsGranted('ROLE_AGENTE')]
    #[Route('/api/mi/configuracion', name: 'app_me_settings', methods: ['GET'])]
    public function getConfiguracion(): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $usuario = $agente->getUsuario();

        // Perfil
        $perfil = [
            'nombre' => $agente->getNombre(),
            'email' => $usuario?->getEmail() ?? '',
            'firma' => $agente->getFirma(),
        ];

        // Notificaciones
        $notificaciones = [
            'sonido' => $agente->hasSonidoDeNotificacion(),
            'escritorio' => $agente->hasNotificacionesDeEscritorio(),
        ];

        // Respuesta automática
        $respuestaAutomatica = [
            'habilitada' => $agente->isRespuestaAutomaticaActiva(),
            'texto' => $agente->getMensajeDeRespuestaAutomatica(),
        ];

        // Espacio de trabajo
        $espacioDeTrabajoActivo = $agente->getEspacioDeTrabajoActivo();
        $datosDelEspacioDeTrabajo = null;
        if ($espacioDeTrabajoActivo !== null) {
            $datosDelEspacioDeTrabajo = [
                'id' => (int) $espacioDeTrabajoActivo->getId(),
                'nombreVisible' => $espacioDeTrabajoActivo->getNombreVisible(),
                'phoneNumberId' => $espacioDeTrabajoActivo->getPhoneNumberId(),
            ];
        }

        // Horario laboral (turnos)
        $horarioDeAtencion = [];
        foreach ($agente->getTurnos() as $turno) {
            $horarioDeAtencion[] = [
                'diaDeLaSemana' => $turno->getDiaDeLaSemana(),
                'inicio' => $turno->getHoraDeInicio()->format('H:i'),
                'fin' => $turno->getHoraDeFin()->format('H:i'),
            ];
        }

        // Ordenar los turnos por diaDeLaSemana y luego por inicio
        \usort($horarioDeAtencion, function (array $a, array $b) {
            return [$a['diaDeLaSemana'], $a['inicio']] <=> [$b['diaDeLaSemana'], $b['inicio']];
        });

        return new JsonResponse([
            'perfil' => $perfil,
            'notificaciones' => $notificaciones,
            'respuestaAutomatica' => $respuestaAutomatica,
            'espacioDeTrabajo' => $datosDelEspacioDeTrabajo,
            'horarioDeAtencion' => $horarioDeAtencion,
        ]);
    }

    #[IsGranted('ROLE_AGENTE')]
    #[Route('/api/mi/perfil', name: 'app_me_profile', methods: ['PUT'])]
    public function updatePerfil(#[MapRequestPayload] UpdatePerfilRequest $request): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updatePerfilDeAgenteUseCase->execute($agente, $request->firma);

        return new JsonResponse([
            'nombre' => $agente->getNombre(),
            'email' => $agente->getUsuario()?->getEmail() ?? '',
            'firma' => $agente->getFirma(),
        ]);
    }

    #[IsGranted('ROLE_AGENTE')]
    #[Route('/api/mi/notificaciones', name: 'app_me_notifications', methods: ['PUT'])]
    public function updateNotificaciones(#[MapRequestPayload] UpdateNotificacionesRequest $request): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updatePreferenciasDeNotificacionUseCase->execute($agente, $request->sonido, $request->escritorio);

        return new JsonResponse([
            'sonido' => $agente->hasSonidoDeNotificacion(),
            'escritorio' => $agente->hasNotificacionesDeEscritorio(),
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
