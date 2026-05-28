<?php

namespace App\Controller;

use App\Application\UseCase\HandleInboundMessageUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

class MessageController
{
    public function __construct(
        private HandleInboundMessageUseCase $handleInboundMessage,
        private LoggerInterface $logger
    ) {}

    #[Route('/messages/inbound', methods: ['POST'])]
    public function inbound(#[MapRequestPayload] InboundMessageRequest $request): JsonResponse
    {
        try {
            $result = $this->handleInboundMessage->processInboundMessage(
                $request->phone,
                $request->message,
                $request->externalId
            );
            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error processing inbound message: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }
}