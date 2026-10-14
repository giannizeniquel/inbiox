<?php

declare(strict_types=1);

namespace App\Conversacion;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MediaTooLargeException extends HttpException
{
    public function __construct(
        private readonly int $maxSize,
        private readonly string $kind,
    ) {
        parent::__construct(
            413,
            sprintf('File exceeds maximum size for %s: %d bytes', $kind, $maxSize),
        );
    }

    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}
