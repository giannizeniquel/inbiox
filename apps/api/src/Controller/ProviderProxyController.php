<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Service\GraphClient;
use App\Domain\Message\Message;
use App\Domain\Message\MessageDirection;
use App\Exception\GraphApiException;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[Route('/api/provider')]
class ProviderProxyController extends AbstractController
{
    private const int GRAPH_AUTH_ERROR = 401;   // Meta auth/oauth errors
    private const int GRAPH_RATE_LIMIT = 429;    // Too many requests

    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly GraphClient $graphClient,
        private readonly EntityManagerInterface $em,
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
        $mediaId = $payload['mediaId'] ?? null;
        $mimeType = $payload['mimeType'] ?? null;
        $caption = $payload['caption'] ?? null;

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

        // --- Multi-number resolution (CAP-7 / META-018) ---
        // Use ticket's phoneNumberId if set, fallback to env default in GraphClient
        $phoneNumberId = $ticket->getPhoneNumberId();

        // --- Call Graph API ---
        try {
            if ($mediaId !== null && $mimeType !== null) {
                $graphResponse = $this->graphClient->sendMediaMessage($phone, $mediaId, $mimeType, $caption ?? $content, $phoneNumberId);
            } else {
                $graphResponse = $this->graphClient->sendMessage($phone, $content, $phoneNumberId);
            }
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

        // Persist WAMID as externalId on a new outbound Message for webhook correlation
        if ($wamid !== null) {
            $message = new Message();
            $message->setContent($content);
            $message->setExternalId($wamid);
            $message->setDirection(MessageDirection::OUTBOUND->value);
            $message->setDeliveryStatus('sent');
            $message->setUpdatedAt(new \DateTimeImmutable());
            if ($ticket->getConversation()) {
                $message->setConversation($ticket->getConversation());
            }
            $this->em->persist($message);
            $this->em->flush();
        }

        return new JsonResponse(
            [
                'messageId' => $wamid,
                'status' => 'sent',
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/template/send', methods: ['POST'])]
    public function sendTemplate(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)
            || !isset($payload['ticketId'])
            || !isset($payload['templateName'])
            || !isset($payload['language'])
        ) {
            return new JsonResponse(
                ['error' => 'Missing required fields: ticketId, templateName, language'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $ticketId = (int) $payload['ticketId'];
        $templateName = $payload['templateName'];
        $language = $payload['language'];
        $parameters = $payload['parameters'] ?? [];

        if (!is_array($parameters)) {
            return new JsonResponse(
                ['error' => 'Parameters must be an array of strings'],
                Response::HTTP_BAD_REQUEST
            );
        }

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

        // --- Multi-number resolution (CAP-7 / META-018) ---
        $phoneNumberId = $ticket->getPhoneNumberId();

        // --- Call Graph API ---
        try {
            $graphResponse = $this->graphClient->sendTemplate($phone, $templateName, $language, $parameters, $phoneNumberId);
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

        // Persist WAMID as externalId on a new outbound Message for webhook correlation
        if ($wamid !== null) {
            $content = "Template: {$templateName}" . (!empty($parameters) ? ' (' . implode(', ', $parameters) . ')' : '');
            $message = new Message();
            $message->setContent($content);
            $message->setExternalId($wamid);
            $message->setDirection(MessageDirection::OUTBOUND->value);
            $message->setDeliveryStatus('sent');
            $message->setUpdatedAt(new \DateTimeImmutable());
            if ($ticket->getConversation()) {
                $message->setConversation($ticket->getConversation());
            }
            $this->em->persist($message);
            $this->em->flush();
        }

        return new JsonResponse(
            [
                'messageId' => $wamid,
                'status' => 'sent',
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/media/upload', methods: ['POST'])]
    public function uploadMedia(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(
                ['error' => 'No valid file provided. Use multipart/form-data with a "file" field.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $filePath = $file->getPathname();
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        try {
            $result = $this->graphClient->uploadMedia($filePath, $mimeType);

            return new JsonResponse(
                ['mediaId' => $result['id'] ?? ''],
                Response::HTTP_OK
            );
        } catch (GraphApiException $e) {
            return new JsonResponse(
                [
                    'type' => 'GRAPH_API_ERROR',
                    'code' => $e->getGraphCode(),
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_BAD_GATEWAY
            );
        }
    }

    #[Route('/media/{mediaId}', methods: ['GET'])]
    public function downloadMedia(string $mediaId): Response
    {
        try {
            $content = $this->graphClient->downloadMedia($mediaId);

            return new Response($content, Response::HTTP_OK);
        } catch (GraphApiException $e) {
            return new JsonResponse(
                [
                    'type' => 'GRAPH_API_ERROR',
                    'code' => $e->getGraphCode(),
                    'message' => $e->getMessage(),
                ],
                $this->mapGraphErrorCode($e->getGraphCode())
            );
        }
    }

    /**
     * Normalize phone number for outbound WhatsApp API calls.
     * DB stores numbers without the 9 prefix (54...), but Meta API requires 549... format.
     */
    private function normalizeOutboundPhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        // Argentine mobile: if stored as 54..., add the 9
        if (str_starts_with($digits, '54') && strlen($digits) >= 11) {
            return '54' . '9' . substr($digits, 2);
        }
        return $digits;
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
