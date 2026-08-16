<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

class MediaUrlService
{
    public function __construct(
        private readonly MediaStorageService $storageService,
        private readonly int $urlTtl = 300,
    ) {}

    /**
     * Generate a pre-signed URL for the given object key.
     *
     * @return array{url: string, expiresAt: \DateTimeImmutable}
     */
    public function temporaryUrl(string $key): array
    {
        $expiresAt = new \DateTimeImmutable("+{$this->urlTtl} seconds");
        $url = $this->storageService->temporaryUrl($key, $this->urlTtl);

        return [
            'url' => $url,
            'expiresAt' => $expiresAt,
        ];
    }
}
