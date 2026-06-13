<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Service\GraphClient;
use App\Exception\GraphApiException;
use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/provider')]
class ProviderProxyController extends AbstractController
{
    private const int GRAPH_AUTH_ERROR = 401;   // Meta auth/oauth errors
    private const int GRAPH_RATE_LIMIT = 429;    // Too many requests

    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly GraphClient $graphClient,
    ) {}

    #[Route('/send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || !isset($payload['ticketId']) || !isset($payload['content'])) {
            return new JsonResponse(
                ['error' => 'Missing required fields: ticketId, content'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $ticketId = (int) $payload['ticketId'];
        $content = $payload['content'];

        // --- Phone Resolution Chain ---
        $ticket = $this->ticketRepository->find($ticketId);

        if (!$ticket) {
            return new JsonResponse(
                ['error' => 'Ticket not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $contact = $ticket->getContact();

        if (!$contact) {
            return new JsonResponse(
                ['type' => 'PHONE_NOT_FOUND', 'message' => 'Ticket has no associated contact'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $phone = $contact->getPhone();

        if ($phone === null || $phone === '') {
            return new JsonResponse(
                ['type' => 'PHONE_NOT_FOUND', 'message' => 'Contact has no phone number'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // --- Call Graph API ---
        try {
            $graphResponse = $this->graphClient->sendMessage($phone, $content);
        } catch (GraphApiException $e) {
            $httpStatus = $this->mapGraphErrorCode($e->getGraphCode());
            $errorType = $this->mapGraphErrorType($e->getGraphCode());

            return new JsonResponse(
                [
                    'type' => $errorType,
                    'code' => $e->getGraphCode(),
                    'message' => $e->getMessage(),
                ],
                $httpStatus
            );
        }

        $wamid = $graphResponse['messages'][0]['id'] ?? null;

        return new JsonResponse(
            [
                'messageId' => $wamid,
                'status' => 'sent',
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Map Graph API error code to HTTP status.
     */
    private function mapGraphErrorCode(int $code): int
    {
        return match ($code) {
            131030, 131031, 131047, 132000 => Response::HTTP_UNPROCESSABLE_ENTITY, // 422
            131050, 132001 => Response::HTTP_TOO_MANY_REQUESTS,                    // 429
            default => Response::HTTP_BAD_GATEWAY,                                 // 502
        };
    }

    /**
     * Map Graph API error code to Inbiox error type.
     */
    private function mapGraphErrorType(int $code): string
    {
        return match ($code) {
            131030 => 'RECIPIENT_NOT_ALLOWED',
            131031 => 'INVALID_RECIPIENT',
            131047 => 'RECIPIENT_BLOCKED',
            132000 => 'MESSAGE_TOO_OLD',
            131050, 132001 => 'RATE_LIMIT_EXCEEDED',
            default => 'GRAPH_API_ERROR',
        };
    }
}
