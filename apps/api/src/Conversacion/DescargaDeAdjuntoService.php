<?php

declare(strict_types=1);

namespace App\Conversacion;

use App\Compartido\Storage\AdjuntoStorageService;

use App\Compartido\Graph\GraphClient;
use App\Compartido\Graph\GraphApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

class DescargaDeAdjuntoService
{
    public function __construct(
        private readonly GraphClient $graphClient,
        private readonly AdjuntoStorageService $adjuntoStorageService,
        private readonly ValidadorDeAdjunto $validadorDeAdjunto,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Download media from Graph API and store it in object storage.
     *
     * @param string $mediaId    WhatsApp media ID
     * @param string $mimeType   MIME type of the media
     * @param string $tenantId   Tenant prefix for the object key
     * @param int    $ticketId   Ticket ID for the object key
     * @param string $filename   Original filename for the extension
     *
     * @return array{key: string, size: int, mimeType: string, kind: string}|null
     *         Null if download or storage fails
     */
    public function descargarYGuardar(
        string $mediaId,
        string $mimeType,
        string $tenantId,
        int $ticketId,
        string $filename = 'file',
    ): ?array {
        // Normalize MIME type: WhatsApp voice notes arrive as
        // "audio/ogg; codecs=opus" but we want the bare "audio/ogg".
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        try {
            $binary = $this->graphClient->downloadMedia($mediaId);
        } catch (GraphApiException $e) {
            $this->logger->error('Failed to download media from Graph API', [
                'mediaId' => $mediaId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $tamanio = strlen($binary);
        $tipo = TipoDeAdjunto::fromMime($mimeType);

        // Validate size (skip validation if graph already sent it — we still enforce)
        try {
            $this->validadorDeAdjunto->validar($mimeType, $tamanio);
        } catch (\Throwable $e) {
            $this->logger->warning('Downloaded media failed validation', [
                'mediaId' => $mediaId,
                'mimeType' => $mimeType,
                'error' => $e->getMessage(),
            ]);
            error_log('MEDIA_VALIDATION_FAILED mediaId=' . $mediaId . ' mimeType=' . $mimeType);
            return null;
        }

        // Build object key: {tenant}/{ticket_id}/{uuid}.{ext}
        $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
        $uuid = Ulid::generate();
        $key = sprintf('%s/%d/%s.%s', $tenantId, $ticketId, $uuid, $ext);

        try {
            $this->adjuntoStorageService->put($key, $binary, $mimeType);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store media in object storage', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return [
            'key' => $key,
            'size' => $tamanio,
            'mimeType' => $mimeType,
            'kind' => $tipo->value,
        ];
    }
}
