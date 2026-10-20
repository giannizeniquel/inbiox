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
 * Sube el adjunto de un agente a WhatsApp y guarda una copia en el almacenamiento
 * de objetos, para que un envío posterior pueda referenciarlo por su media id de Graph.
 */
final class SubirAdjuntoUseCase
{
    public function __construct(
        private readonly ValidadorDeAdjunto $validadorDeAdjunto,
        private readonly GraphClient $graphClient,
        private readonly AdjuntoStorageService $adjuntoStorageService,
    ) {}

    /**
     * Guardar la copia es best effort: si falla, `key` es null y la
     * subida igual se completa.
     *
     * `$ticketId` es el valor crudo del formulario; solo da nombre a la clave de almacenamiento.
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
            // Un ticketId no escalar falla en este paso, igual que cuando se leía con InputBag::get().
            if ($ticketId !== null && !\is_scalar($ticketId)) {
                throw new \UnexpectedValueException('ticketId must be a scalar');
            }

            $tenantId = 'default';
            $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
            $s3Key = sprintf('%s/%d/%s.%s', $tenantId, (int) ($ticketId ?: 0), Ulid::generate(), $ext);

            $this->adjuntoStorageService->put($s3Key, file_get_contents($filePath), $mimeType);
            $key = $s3Key;
        } catch (\Throwable) {
            // No es fatal: quien llama ya tiene el media id de WhatsApp.
        }

        return [
            'mediaId' => $mediaId,
            'key' => $key,
        ];
    }
}
