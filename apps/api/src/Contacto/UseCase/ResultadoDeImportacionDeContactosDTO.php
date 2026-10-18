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
     * @param list<array{row: int, phone: string, reason: string, message: string}> $errors
     */
    public function __construct(
        public readonly int $totalRows,
        public readonly int $imported,
        public readonly int $failed,
        public readonly array $errors,
        public readonly bool $errorsTruncated,
    ) {}

    public function toArray(): array
    {
        return [
            'totalRows' => $this->totalRows,
            'imported' => $this->imported,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'errorsTruncated' => $this->errorsTruncated,
        ];
    }
}
