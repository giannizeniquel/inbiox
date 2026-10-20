<?php

declare(strict_types=1);

namespace App\Conversacion;

use App\Compartido\Storage\AdjuntoStorageService;

class UrlDeAdjuntoService
{
    public function __construct(
        private readonly AdjuntoStorageService $adjuntoStorageService,
        private readonly int $urlTtl = 300,
    ) {}

    /**
     * Genera una URL prefirmada para la clave de objeto indicada.
     *
     * @return array{url: string, expiresAt: \DateTimeImmutable}
     */
    public function getUrlTemporal(string $key): array
    {
        $expiresAt = new \DateTimeImmutable("+{$this->urlTtl} seconds");
        $url = $this->adjuntoStorageService->temporaryUrl($key, $this->urlTtl);

        return [
            'url' => $url,
            'expiresAt' => $expiresAt,
        ];
    }
}
