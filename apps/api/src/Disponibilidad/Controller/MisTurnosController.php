<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Administracion\User;
use App\Agente\Agente;
use App\Disponibilidad\UseCase\ReemplazarTurnosUseCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The current agent's shifts.
 *
 * GET /api/me/shifts — list current agent's shifts
 * PUT /api/me/shifts — replace all shifts
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MisTurnosController
{
    public function __construct(
        private readonly Security $security,
        private readonly ReemplazarTurnosUseCase $reemplazarTurnosUseCase,
    ) {}

    #[IsGranted('ROLE_AGENT')]
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

        // Sort by dayOfWeek then start
        \usort($turnos, function (array $a, array $b) {
            return [$a['dayOfWeek'], $a['start']] <=> [$b['dayOfWeek'], $b['start']];
        });

        return new JsonResponse($turnos);
    }

    #[IsGranted('ROLE_AGENT')]
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

        if (!$usuario instanceof User) {
            return null;
        }

        return $usuario->getAgent();
    }
}
