<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Administracion\User;
use App\Agente\Agent;
use App\Disponibilidad\UseCase\ReplaceAgentShiftsUseCase;
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
final class MeShiftsController
{
    public function __construct(
        private readonly Security $security,
        private readonly ReplaceAgentShiftsUseCase $replaceAgentShiftsUseCase,
    ) {}

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/shifts', name: 'app_me_shifts_list', methods: ['GET'])]
    public function getShifts(): JsonResponse
    {
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
    }

    #[IsGranted('ROLE_AGENT')]
    #[Route('/api/me/shifts', name: 'app_me_shifts_replace', methods: ['PUT'])]
    public function replaceShifts(#[MapRequestPayload] ReplaceShiftsRequest $request): JsonResponse
    {
        $agent = $this->getAgent();
        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $created = $this->replaceAgentShiftsUseCase->execute((int) $agent->getId(), $request->shifts);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($created);
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
