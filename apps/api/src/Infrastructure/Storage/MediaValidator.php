<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Media\MediaKind;
use App\Exception\MediaTooLargeException;
use App\Exception\MediaTypeNotAllowedException;

class MediaValidator
{
    private const MAX_SIZE = [
        'image' => 5 * 1024 * 1024,     // 5 MB
        'video' => 16 * 1024 * 1024,    // 16 MB
        'audio' => 16 * 1024 * 1024,    // 16 MB
        'document' => 100 * 1024 * 1024, // 100 MB
    ];

    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'audio/ogg',
        'audio/mpeg',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Validate a file upload against size caps and MIME whitelist.
     *
     * @throws MediaTooLargeException if file exceeds kind-specific cap
     * @throws MediaTypeNotAllowedException if MIME type is not in whitelist
     */
    public function validate(string $mimeType, int $fileSize): void
    {
        $this->validateMimeType($mimeType);
        $this->validateSize($mimeType, $fileSize);
    }

    /**
     * Get the max allowed size for a given MIME type.
     */
    public function getMaxSize(string $mimeType): int
    {
        $kind = MediaKind::fromMime($mimeType);
        return self::MAX_SIZE[$kind->value];
    }

    private function validateMimeType(string $mimeType): void
    {
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            throw new MediaTypeNotAllowedException($mimeType);
        }
    }

    private function validateSize(string $mimeType, int $fileSize): void
    {
        $kind = MediaKind::fromMime($mimeType);
        $maxSize = self::MAX_SIZE[$kind->value];

        if ($fileSize > $maxSize) {
            throw new MediaTooLargeException($maxSize, $kind->value);
        }
    }
}
