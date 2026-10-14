<?php

declare(strict_types=1);

namespace App\Conversacion;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MediaTypeNotAllowedException extends HttpException
{
    public function __construct(
        private readonly string $mimeType,
    ) {
        parent::__construct(
            415,
            sprintf('Unsupported media type: %s', $mimeType),
        );
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }
}
