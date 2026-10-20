<?php

declare(strict_types=1);

namespace App\Conversacion;

enum TipoDeAdjunto: string
{
    case IMAGEN = 'imagen';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENTO = 'documento';

    /**
     * El tipo de media que usa la API de WhatsApp de Meta (`type` del mensaje). Es el único
     * lugar que traduce entre los valores de Inbiox y los de Meta.
     */
    public function valorDeMeta(): string
    {
        return match ($this) {
            self::IMAGEN => 'image',
            self::VIDEO => 'video',
            self::AUDIO => 'audio',
            self::DOCUMENTO => 'document',
        };
    }

    /**
     * Asocia un tipo MIME con su TipoDeAdjunto.
     * Los tipos MIME desconocidos se tratan como DOCUMENTO.
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
     * Asocia un TipoDeAdjunto con su extensión de archivo.
     */
    public static function toExtension(TipoDeAdjunto $tipo): string
    {
        return match ($tipo) {
            self::IMAGEN => 'jpg',
            self::VIDEO => 'mp4',
            self::AUDIO => 'ogg',
            self::DOCUMENTO => 'pdf',
        };
    }
}
