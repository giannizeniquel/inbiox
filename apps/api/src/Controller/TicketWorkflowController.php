<?php

namespace App\Controller;

use App\Application\UseCase\TransitionTicketStatusUseCase;
use App\Application\UseCase\ResolveTicketUseCase;
use App\Domain\Ticket\TicketStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class TicketWorkflowController
{
    public function __construct(
        private TransitionTicketStatusUseCase $transitionUseCase,
        private ResolveTicketUseCase $resolveTicketUseCase,
        private LoggerInterface $logger,
    ) {}

    #[Route('/tickets/{id}/transition', methods: ['POST'])]
    public function transition(int $id, \Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $targetStatus = $data['status'] ?? null;

            if (!$targetStatus) {
                return new JsonResponse(['error' => 'status is required'], 400);
            }

            $result = $this->transitionUseCase->execute(
                $id,
                $targetStatus,
                $data['set_first_response'] ?? false,
                $data['set_resolved'] ?? false
            );

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\App\Domain\Ticket\InvalidTransitionException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            $refId = bin2hex(random_bytes(8));
            $this->logger->error('Transition error: ' . $e->getMessage(), ['ref' => $refId]);
            return new JsonResponse(['error' => 'Internal error', 'reference' => $refId], 500);
        }
    }

    #[Route('/tickets/{id}/resolve', methods: ['POST'])]
    public function resolve(int $id): JsonResponse
    {
        try {
            $result = $this->resolveTicketUseCase->execute($id);
            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $refId = bin2hex(random_bytes(8));
            $this->logger->error('Resolve error: ' . $e->getMessage(), ['ref' => $refId]);
            return new JsonResponse(['error' => 'Internal error', 'reference' => $refId], 500);
        }
    }

    #[Route('/tickets/{id}/close', methods: ['POST'])]
    public function close(int $id): JsonResponse
    {
        try {
            $result = $this->transitionUseCase->close($id);
            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $refId = bin2hex(random_bytes(8));
            $this->logger->error('Close error: ' . $e->getMessage(), ['ref' => $refId]);
            return new JsonResponse(['error' => 'Internal error', 'reference' => $refId], 500);
        }
    }
}