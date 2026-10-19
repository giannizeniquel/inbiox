<?php

declare(strict_types=1);

namespace App\Conversacion;

class ValidadorDeAdjunto
{
    private const TAMANIO_MAXIMO = [
        'image' => 5 * 1024 * 1024,     // 5 MB
        'video' => 16 * 1024 * 1024,    // 16 MB
        'audio' => 16 * 1024 * 1024,    // 16 MB
        'document' => 100 * 1024 * 1024, // 100 MB
    ];

    private const MIME_PERMITIDOS = [
        // images
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/svg+xml',
        // video
        'video/mp4',
        'video/webm',
        // audio
        'audio/ogg',
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/aac',
        'audio/x-m4a',
        // documents
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // presentations
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // archives
        'application/zip',
        'application/x-zip-compressed',
        'application/x-rar-compressed',
        'application/vnd.rar',
        'application/x-rar',
        'application/rar',
        'application/x-compressed',
        'application/x-7z-compressed',
        'application/x-tar',
        'application/gzip',
        'application/x-gzip',
        // executables
        'application/x-msdownload',
        'application/vnd.microsoft.portable-executable',
        'application/x-msdos-program',
        // generic binary (catch-all for unknown types)
        'application/octet-stream',
    ];

    /**
     * Validate a file upload against size caps and MIME whitelist.
     *
     * @throws AdjuntoDemasiadoGrandeException if file exceeds kind-specific cap
     * @throws MimeDeAdjuntoNoPermitidoException if MIME type is not in whitelist
     */
    public function validar(string $mimeType, int $tamanioDeArchivo): void
    {
        $this->validarMime($mimeType);
        $this->validarTamanio($mimeType, $tamanioDeArchivo);
    }

    /**
     * Get the max allowed size for a given MIME type.
     */
    public function getTamanioMaximo(string $mimeType): int
    {
        $tipo = TipoDeAdjunto::fromMime($mimeType);
        return self::TAMANIO_MAXIMO[$tipo->value];
    }

    private function validarMime(string $mimeType): void
    {
        $normalized = $this->normalizarMime($mimeType);
        if (!in_array($normalized, self::MIME_PERMITIDOS, true)) {
            throw new MimeDeAdjuntoNoPermitidoException($mimeType);
        }
    }

    /**
     * Normalize a MIME type by stripping parameters (e.g. "; codecs=opus")
     * and lowercasing. WhatsApp voice notes arrive as "audio/ogg; codecs=opus"
     * but the whitelist stores the bare "audio/ogg".
     */
    private function normalizarMime(string $mimeType): string
    {
        return strtolower(trim(explode(';', $mimeType)[0]));
    }

    private function validarTamanio(string $mimeType, int $tamanioDeArchivo): void
    {
        $tipo = TipoDeAdjunto::fromMime($mimeType);
        $tamanioMaximo = self::TAMANIO_MAXIMO[$tipo->value];

        if ($tamanioDeArchivo > $tamanioMaximo) {
            throw new AdjuntoDemasiadoGrandeException($tamanioMaximo, $tipo->value);
        }
    }
}
