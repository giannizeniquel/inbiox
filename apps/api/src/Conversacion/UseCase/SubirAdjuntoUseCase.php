<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\Storage\AdjuntoStorageService;
use App\Conversacion\AdjuntoDemasiadoGrandeException;
use App\Conversacion\MimeDeAdjuntoNoPermitidoException;
use App\Conversacion\ValidadorDeAdjunto;
use Symfony\Component\Uid\Ulid;

/**
 * Uploads an agent's attachment to WhatsApp and keeps a copy in object
 * storage, so a later send can reference it by its Graph media id.
 */
final class SubirAdjuntoUseCase
{
    public function __construct(
        private readonly ValidadorDeAdjunto $validadorDeAdjunto,
        private readonly GraphClient $graphClient,
        private readonly AdjuntoStorageService $adjuntoStorageService,
    ) {}

    /**
     * Storing the copy is best effort: when it fails, `key` is null and the
     * upload still succeeds.
     *
     * `$ticketId` is the raw form value; it only names the storage key.
     *
     * @return array{mediaId: mixed, key: ?string}
     *
     * @throws AdjuntoDemasiadoGrandeException
     * @throws MimeDeAdjuntoNoPermitidoException
     * @throws GraphApiException
     */
    public function execute(string $filePath, string $mimeType, int $tamanioDeArchivo, string $filename, mixed $ticketId): array
    {
        $this->validadorDeAdjunto->validar($mimeType, $tamanioDeArchivo);

        $graphResult = $this->graphClient->uploadMedia($filePath, $mimeType);
        $mediaId = $graphResult['id'] ?? '';

        $key = null;
        try {
            // A non-scalar ticketId fails this step, as it did when it was read with InputBag::get().
            if ($ticketId !== null && !\is_scalar($ticketId)) {
                throw new \UnexpectedValueException('ticketId must be a scalar');
            }

            $tenantId = 'default';
            $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
            $s3Key = sprintf('%s/%d/%s.%s', $tenantId, (int) ($ticketId ?: 0), Ulid::generate(), $ext);

            $this->adjuntoStorageService->put($s3Key, file_get_contents($filePath), $mimeType);
            $key = $s3Key;
        } catch (\Throwable) {
            // Non-fatal: the caller already has the media id from WhatsApp.
        }

        return [
            'mediaId' => $mediaId,
            'key' => $key,
        ];
    }
}
