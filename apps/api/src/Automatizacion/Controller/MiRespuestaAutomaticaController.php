<?php

declare(strict_types=1);

namespace App\Automatizacion\Controller;

use App\Administracion\User;
use App\Agente\Agent;
use App\Automatizacion\UseCase\UpdateConfiguracionDeRespuestaAutomaticaUseCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The current agent's auto-reply settings.
 *
 * PUT /api/me/auto-reply — update auto-reply settings
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MiRespuestaAutomaticaController
{
    public function __construct(
        private readonly Security $security,
        private readonly UpdateConfiguracionDeRespuestaAutomaticaUseCase $updateAutoReplySettingsUseCase,
    ) {}

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/auto-reply', name: 'app_me_auto_reply', methods: ['PUT'])]
    public function updateRespuestaAutomatica(#[MapRequestPayload] UpdateRespuestaAutomaticaRequest $request): JsonResponse
    {
        $agent = $this->getAgente();
        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $this->updateAutoReplySettingsUseCase->execute($agent, $request->enabled, $request->message);

        return new JsonResponse([
            'enabled' => $agent->isAutoReplyEnabled(),
            'message' => $agent->getAutoReplyMessage(),
        ]);
    }

    private function getAgente(): ?Agent
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return $user->getAgent();
    }
}
