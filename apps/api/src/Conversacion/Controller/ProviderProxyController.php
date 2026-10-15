<?php

declare(strict_types=1);

namespace App\Conversacion\Controller;

use App\Compartido\Graph\GraphClient;
use App\Agente\UseCase\EnsureAgentForUserUseCase;
use App\Conversacion\UseCase\HandleOutboundMessageUseCase;
use App\Conversacion\UseCase\MarkOutboundMessageSentUseCase;
use App\Conversacion\UseCase\RecordSentTemplateUseCase;
use App\Conversacion\MediaKind;
use App\Administracion\User;
use App\Conversacion\ConversationWindowExpiredException;
use App\Compartido\Graph\GraphApiException;
use App\Conversacion\MediaTooLargeException;
use App\Conversacion\MediaTypeNotAllowedException;
use App\Compartido\StateConflictException;
use App\Compartido\Storage\MediaStorageService;
use App\Conversacion\MediaValidator;
use App\Ticket\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('ROLE_AGENT')]
class ProviderProxyController extends AbstractController
{
    private const GRAPH_AUTH_ERROR = 401;   // Meta auth/oauth errors
    private const GRAPH_RATE_LIMIT = 429;    // Too many requests

    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly GraphClient $graphClient,
        private readonly HandleOutboundMessageUseCase $outboundUseCase,
        private readonly MarkOutboundMessageSentUseCase $markOutboundMessageSent,
        private readonly RecordSentTemplateUseCase $recordSentTemplate,
        private readonly EnsureAgentForUserUseCase $ensureAgentForUser,
        private readonly MediaValidator $mediaValidator,
        private readonly MediaStorageService $mediaStorageService,
    ) {}

    #[Route('/api/provider/send', name: 'app_provider_send', methods: ['POST'])]
    public function send(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
        $mediaKey = $payload['mediaKey'] ?? null;
        $filename = $payload['filename'] ?? null;
        $size = $payload['size'] ?? null;

        // Resolve agent ID from authenticated user (degrades to null, never
        // fails the send — design.md D4).
        $agentId = $this->resolveActingAgentId($user) ?? 0;

        // Resolved/closed ownership guard (design.md D3): role_hierarchy makes
        // ROLE_ADMIN inherit ROLE_SUPERVISOR, so this single check covers both.
        $senderIsPrivileged = $this->isGranted('ROLE_SUPERVISOR');

        // Build media metadata for persistence
        $mediaMetadata = null;
        if ($mediaId !== null && $mimeType !== null) {
            $mediaMetadata = [
                'providerId' => $mediaId,
                'mimeType' => $mimeType,
                'key' => $mediaKey,
                'caption' => $caption,
                'filename' => $filename,
                'size' => $size,
                'kind' => MediaKind::fromMime($mimeType)->value,
            ];
        }

        // --- Process outbound message (creates Message, handles assignment + status) ---
        // This single call site covers both free-text and media sends (the media
        // metadata, when present, is passed through above) — one catch below
        // guards both.
        try {
            $result = $this->outboundUseCase->processOutboundMessage(
                ticketId: $ticketId,
                content: $content,
                agentId: $agentId,
                mediaMetadata: $mediaMetadata,
                senderIsPrivileged: $senderIsPrivileged,
            );
        } catch (ConversationWindowExpiredException $e) {
            return new JsonResponse(
                [
                    'type' => 'CONVERSATION_WINDOW_EXPIRED',
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (StateConflictException $e) {
            return new JsonResponse(
                [
                    'type' => 'TICKET_NOT_OWNED',
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_CONFLICT
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
            if ($mediaId !== null && $mimeType !== null) {
                $graphResponse = $this->graphClient->sendMediaMessage($phone, $mediaId, $mimeType, $caption ?? $content, $phoneNumberId, $filename);
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

        // Set WAMID on the message created by HandleOutboundMessageUseCase
        if ($wamid !== null && !empty($result['message_id'])) {
            $this->markOutboundMessageSent->execute($result['message_id'], $wamid);
        }

        return new JsonResponse(
            [
                // DB message ID — the frontend uses this to fetch the media URL
                'messageId' => $result['message_id'] ?? null,
                // WhatsApp message ID (WAMID)
                'externalId' => $wamid,
                'status' => 'sent',
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Resolve the acting agent's id from the authenticated user, degrading
     * gracefully (never throwing) rather than failing the caller's send.
     *
     * If the user doesn't have a linked Agent record (e.g. admin), create or
     * find one so they can be assigned to tickets. Extracted from send()'s
     * original inline fallback (design.md D4).
     */
    private function resolveActingAgentId(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        try {
            return $this->ensureAgentForUser->execute($user)->getId();
        } catch (\Throwable) {
            return null;
        }
    }

    #[Route('/api/provider/template/send', name: 'app_provider_template_send', methods: ['POST'])]
    public function sendTemplate(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
            $this->recordSentTemplate->execute($ticket, $templateName, $parameters, $wamid, $this->resolveActingAgentId($user));
        }

        return new JsonResponse(
            [
                'messageId' => $wamid,
                'status' => 'sent',
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/api/provider/media/upload', name: 'app_provider_media_upload', methods: ['POST'])]
    public function uploadMedia(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
        $fileSize = $file->getSize();
        $filename = $file->getClientOriginalName();

        // 1. Validate MIME and size
        try {
            $this->mediaValidator->validate($mimeType, $fileSize);
        } catch (MediaTooLargeException $e) {
            return new JsonResponse(
                [
                    'error' => 'FILE_TOO_LARGE',
                    'maxSize' => $e->getMaxSize(),
                    'kind' => $e->getKind(),
                ],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            );
        } catch (MediaTypeNotAllowedException $e) {
            return new JsonResponse(
                [
                    'error' => 'UNSUPPORTED_TYPE',
                    'mimeType' => $e->getMimeType(),
                ],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE
            );
        }

        // 2. Upload to WhatsApp Graph API
        try {
            $graphResult = $this->graphClient->uploadMedia($filePath, $mimeType);
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

        $mediaId = $graphResult['id'] ?? '';

        // 3. Store in object storage (optional — best effort)
        $key = null;
        try {
            $tenantId = 'default';
            $ticketId = (int) ($request->request->get('ticketId') ?: 0);
            $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
            $uuid = Ulid::generate();
            $s3Key = sprintf('%s/%d/%s.%s', $tenantId, $ticketId, $uuid, $ext);

            $this->mediaStorageService->put($s3Key, file_get_contents($filePath), $mimeType);
            $key = $s3Key;
        } catch (\Throwable $e) {
            // Storage failure is non-fatal — we already have the mediaId from WhatsApp
        }

        return new JsonResponse(
            [
                'mediaId' => $mediaId,
                'key' => $key,
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/api/provider/media/{mediaId}', name: 'app_provider_media_download', methods: ['GET'])]
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
