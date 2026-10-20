<?php

declare(strict_types=1);

namespace App\Automatizacion\Controller;

use App\Administracion\Usuario;
use App\Agente\Agente;
use App\Automatizacion\UseCase\UpdateConfiguracionDeRespuestaAutomaticaUseCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La configuración de respuesta automática del agente actual.
 *
 * PUT /api/me/auto-reply — actualiza la configuración de respuesta automática
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MiRespuestaAutomaticaController
{
    public function __construct(
        private readonly Security $security,
        private readonly UpdateConfiguracionDeRespuestaAutomaticaUseCase $updateConfiguracionDeRespuestaAutomaticaUseCase,
    ) {}

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/auto-reply', name: 'app_me_auto_reply', methods: ['PUT'])]
    public function updateRespuestaAutomatica(#[MapRequestPayload] UpdateRespuestaAutomaticaRequest $request): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updateConfiguracionDeRespuestaAutomaticaUseCase->execute($agente, $request->enabled, $request->message);

        return new JsonResponse([
            'enabled' => $agente->isRespuestaAutomaticaActiva(),
            'message' => $agente->getMensajeDeRespuestaAutomatica(),
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
