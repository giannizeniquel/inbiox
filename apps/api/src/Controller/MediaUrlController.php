<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use App\Domain\Message\Message;
use App\Infrastructure\Storage\MediaUrlService;
use App\Repository\MessageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/messages/{id}/media', name: 'app_message_media_url', methods: ['GET'], requirements: ['id' => '\d+'])]
class MediaUrlController extends AbstractController
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly MediaUrlService $mediaUrlService,
    ) {}

    /**
     * GET /api/messages/{id}/media
     *
     * Returns a pre-signed URL for accessing the message's media attachment.
     * Requires ACCESS authorization on the message (via MessageVoter).
     *
     * @return JsonResponse
     */
    #[IsGranted('ACCESS', subject: 'message')]
    public function __invoke(Message $message): JsonResponse
    {
        $media = $message->getMedia();

        if ($media === null || !isset($media['key'])) {
            return new JsonResponse(
                ['error' => 'No media attached to this message'],
                Response::HTTP_NOT_FOUND
            );
        }

        $urlData = $this->mediaUrlService->temporaryUrl($media['key']);

        return new JsonResponse([
            'url' => $urlData['url'],
            'expiresAt' => $urlData['expiresAt']->format(\DateTimeInterface::RFC3339),
        ]);
    }
}
