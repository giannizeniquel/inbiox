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
     * Descarga el adjunto desde Graph API y lo guarda en el almacenamiento de objetos.
     *
     * @param string $mediaId    ID del adjunto en WhatsApp
     * @param string $mimeType   Tipo MIME del adjunto
     * @param string $tenantId   Prefijo del tenant para la clave del objeto
     * @param int    $ticketId   ID del ticket para la clave del objeto
     * @param string $filename   Nombre de archivo original, para la extensión
     *
     * @return array{key: string, size: int, mimeType: string, kind: string}|null
     *         Null si falla la descarga o el almacenamiento
     */
    public function descargarYGuardar(
        string $mediaId,
        string $mimeType,
        string $tenantId,
        int $ticketId,
        string $filename = 'file',
    ): ?array {
        // Normaliza el tipo MIME: las notas de voz de WhatsApp llegan como
        // "audio/ogg; codecs=opus", pero queremos el "audio/ogg" sin parámetros.
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

        // Valida el tamaño (omitir la validación si graph ya lo envió; igual se aplica)
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

        // Arma la clave del objeto: {tenant}/{ticket_id}/{uuid}.{ext}
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
