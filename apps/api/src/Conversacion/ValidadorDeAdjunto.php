<?php

declare(strict_types=1);

namespace App\Conversacion;

class ValidadorDeAdjunto
{
    private const TAMANIO_MAXIMO = [
        TipoDeAdjunto::IMAGEN->value => 5 * 1024 * 1024,       // 5 MB
        TipoDeAdjunto::VIDEO->value => 16 * 1024 * 1024,       // 16 MB
        TipoDeAdjunto::AUDIO->value => 16 * 1024 * 1024,       // 16 MB
        TipoDeAdjunto::DOCUMENTO->value => 100 * 1024 * 1024,  // 100 MB
    ];

    private const MIME_PERMITIDOS = [
        // imágenes
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
        // documentos
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // presentaciones
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // archivos comprimidos
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
        // ejecutables
        'application/x-msdownload',
        'application/vnd.microsoft.portable-executable',
        'application/x-msdos-program',
        // binario genérico (comodín para tipos desconocidos)
        'application/octet-stream',
    ];

    /**
     * Valida un archivo subido contra los límites de tamaño y la lista blanca de tipos MIME.
     *
     * @throws AdjuntoDemasiadoGrandeException si el archivo supera el límite de su tipo
     * @throws MimeDeAdjuntoNoPermitidoException si el tipo MIME no está en la lista blanca
     */
    public function validar(string $mimeType, int $tamanioDeArchivo): void
    {
        $this->validarMime($mimeType);
        $this->validarTamanio($mimeType, $tamanioDeArchivo);
    }

    /**
     * Obtiene el tamaño máximo permitido para un tipo MIME.
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
     * Normaliza un tipo MIME quitando los parámetros (p. ej. "; codecs=opus")
     * y pasándolo a minúsculas. Las notas de voz de WhatsApp llegan como "audio/ogg; codecs=opus",
     * pero la lista blanca guarda el "audio/ogg" sin parámetros.
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
