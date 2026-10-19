<?php

declare(strict_types=1);

namespace App\Conversacion\Controller;

use Symfony\Component\Routing\Attribute\Route;
use App\Conversacion\Mensaje;
use App\Conversacion\UrlDeAdjuntoService;
use App\Conversacion\Repository\MensajeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/messages/{id}/media', name: 'app_message_media_url', methods: ['GET'], requirements: ['id' => '\d+'])]
class UrlDeAdjuntoController extends AbstractController
{
    public function __construct(
        private readonly MensajeRepository $mensajeRepository,
        private readonly UrlDeAdjuntoService $urlDeAdjuntoService,
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
    public function __invoke(Mensaje $mensaje): JsonResponse
    {
        $adjunto = $mensaje->getAdjunto();

        if ($adjunto === null || !isset($adjunto['key'])) {
            return new JsonResponse(
                ['error' => 'No media attached to this message'],
                Response::HTTP_NOT_FOUND
            );
        }

        $urlData = $this->urlDeAdjuntoService->getUrlTemporal($adjunto['key']);

        return new JsonResponse([
            'url' => $urlData['url'],
            'expiresAt' => $urlData['expiresAt']->format(\DateTimeInterface::RFC3339),
        ]);
    }
}
