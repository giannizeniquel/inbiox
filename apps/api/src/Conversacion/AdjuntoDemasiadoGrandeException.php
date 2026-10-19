<?php

declare(strict_types=1);

namespace App\Conversacion;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AdjuntoDemasiadoGrandeException extends HttpException
{
    public function __construct(
        private readonly int $tamanioMaximo,
        private readonly string $tipo,
    ) {
        parent::__construct(
            413,
            sprintf('File exceeds maximum size for %s: %d bytes', $tipo, $tamanioMaximo),
        );
    }

    public function getTamanioMaximo(): int
    {
        return $this->tamanioMaximo;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }
}
