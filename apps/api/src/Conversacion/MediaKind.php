<?php

declare(strict_types=1);

namespace App\Conversacion;

enum MediaKind: string
{
    case IMAGE = 'image';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';

    /**
     * Map a MIME type to the corresponding MediaKind.
     * Unknown MIME types default to DOCUMENT.
     */
    public static function fromMime(string $mimeType): self
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::IMAGE,
            str_starts_with($mimeType, 'video/') => self::VIDEO,
            str_starts_with($mimeType, 'audio/') => self::AUDIO,
            default => self::DOCUMENT,
        };
    }

    /**
     * Map a MediaKind to its file extension.
     */
    public static function toExtension(MediaKind $kind): string
    {
        return match ($kind) {
            self::IMAGE => 'jpg',
            self::VIDEO => 'mp4',
            self::AUDIO => 'ogg',
            self::DOCUMENT => 'pdf',
        };
    }
}
