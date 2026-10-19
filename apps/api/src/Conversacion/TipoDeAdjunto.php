<?php

declare(strict_types=1);

namespace App\Conversacion;

enum TipoDeAdjunto: string
{
    case IMAGEN = 'image';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENTO = 'document';

    /**
     * Map a MIME type to the corresponding TipoDeAdjunto.
     * Unknown MIME types default to DOCUMENTO.
     */
    public static function fromMime(string $mimeType): self
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::IMAGEN,
            str_starts_with($mimeType, 'video/') => self::VIDEO,
            str_starts_with($mimeType, 'audio/') => self::AUDIO,
            default => self::DOCUMENTO,
        };
    }

    /**
     * Map a TipoDeAdjunto to its file extension.
     */
    public static function toExtension(TipoDeAdjunto $kind): string
    {
        return match ($kind) {
            self::IMAGEN => 'jpg',
            self::VIDEO => 'mp4',
            self::AUDIO => 'ogg',
            self::DOCUMENTO => 'pdf',
        };
    }
}
