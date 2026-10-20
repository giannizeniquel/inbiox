<?php

declare(strict_types=1);

namespace App\Asignacion\Controller;

use App\Asignacion\UseCase\AsignarTicketAutomaticamenteUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class AsignacionAutomaticaController
{
    public function __construct(
        private AsignarTicketAutomaticamenteUseCase $asignarTicketAutomaticamenteUseCase,
    ) {}

    #[Route('/api/tickets/{id}/auto-assign', name: 'app_ticket_auto_assign', methods: ['POST'])]
    public function asignarAutomaticamente(int $id): JsonResponse
    {
        // Valida el ID del ticket
        if ($id <= 0) {
            return new JsonResponse(['error' => 'Invalid ticket ID'], 400);
        }

        $result = $this->asignarTicketAutomaticamenteUseCase->execute($id);
        return new JsonResponse($result);
    }
}
