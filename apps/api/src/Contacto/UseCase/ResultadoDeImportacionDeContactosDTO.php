<?php

declare(strict_types=1);

namespace App\Contacto\UseCase;

/**
 * Partial-success result of a CSV contact import.
 *
 * @phpstan-type ImportErrorEntry array{row: int, phone: string, reason: string, message: string}
 */
final class ResultadoDeImportacionDeContactosDTO
{
    /**
     * @param list<array{row: int, phone: string, reason: string, message: string}> $errores
     */
    public function __construct(
        public readonly int $totalDeFilas,
        public readonly int $importados,
        public readonly int $fallidos,
        public readonly array $errores,
        public readonly bool $erroresTruncados,
    ) {}

    public function toArray(): array
    {
        return [
            'totalRows' => $this->totalDeFilas,
            'imported' => $this->importados,
            'failed' => $this->fallidos,
            'errors' => $this->errores,
            'errorsTruncated' => $this->erroresTruncados,
        ];
    }
}
