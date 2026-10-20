<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Administracion\Usuario;
use App\Agente\Agente;
use App\Disponibilidad\UseCase\ReemplazarTurnosUseCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los turnos del agente actual.
 *
 * GET /api/me/shifts — lista los turnos del agente actual
 * PUT /api/me/shifts — reemplaza todos los turnos
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MisTurnosController
{
    public function __construct(
        private readonly Security $security,
        private readonly ReemplazarTurnosUseCase $reemplazarTurnosUseCase,
    ) {}

    #[IsGranted('ROLE_AGENTE')]
    #[Route('/api/me/shifts', name: 'app_me_shifts_list', methods: ['GET'])]
    public function getTurnos(): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $turnos = [];
        foreach ($agente->getTurnos() as $turno) {
            $turnos[] = [
                'dayOfWeek' => $turno->getDiaDeLaSemana(),
                'start' => $turno->getHoraDeInicio()->format('H:i'),
                'end' => $turno->getHoraDeFin()->format('H:i'),
            ];
        }

        // Ordena por dayOfWeek y luego por start
        \usort($turnos, function (array $a, array $b) {
            return [$a['dayOfWeek'], $a['start']] <=> [$b['dayOfWeek'], $b['start']];
        });

        return new JsonResponse($turnos);
    }

    #[IsGranted('ROLE_AGENTE')]
    #[Route('/api/me/shifts', name: 'app_me_shifts_replace', methods: ['PUT'])]
    public function reemplazarTurnos(#[MapRequestPayload] ReemplazarTurnosRequest $request): JsonResponse
    {
        $agente = $this->getAgente();
        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $creado = $this->reemplazarTurnosUseCase->execute((int) $agente->getId(), $request->shifts);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($creado);
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
