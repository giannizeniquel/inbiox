<?php

declare(strict_types=1);

namespace App\Conversacion;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AdjuntoDemasiadoGrandeException extends HttpException
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

    public function getTamanioMaximo(): int
    {
        return $this->maxSize;
    }

    public function getTipo(): string
    {
        return $this->kind;
    }
}
