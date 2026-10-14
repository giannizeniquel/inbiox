<?php

declare(strict_types=1);

namespace App\Asignacion\Controller;

use App\Asignacion\UseCase\AutoAssignTicketUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class AutoAssignController
{
    public function __construct(
        private AutoAssignTicketUseCase $autoAssignTicketUseCase,
        private LoggerInterface $logger,
    ) {}

    #[Route('/api/tickets/{id}/auto-assign', name: 'app_ticket_auto_assign', methods: ['POST'])]
    public function autoAssign(int $id): JsonResponse
    {
        // Validate ticket ID
        if ($id <= 0) {
            return new JsonResponse(['error' => 'Invalid ticket ID'], 400);
        }

        try {
            $result = $this->autoAssignTicketUseCase->execute($id);
            return new JsonResponse($result);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error auto-assigning ticket: ' . $e->getMessage(), [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }
}
