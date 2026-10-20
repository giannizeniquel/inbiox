<?php

declare(strict_types=1);

namespace App\Contacto\UseCase;

/**
 * Resultado de éxito parcial de una importación de contactos por CSV.
 *
 * @phpstan-type ImportErrorEntry array{fila: int, telefono: string, motivo: string, mensaje: string}
 */
final class ResultadoDeImportacionDeContactosDTO
{
    /**
     * @param list<array{fila: int, telefono: string, motivo: string, mensaje: string}> $errores
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
            'totalDeFilas' => $this->totalDeFilas,
            'importados' => $this->importados,
            'fallidos' => $this->fallidos,
            'errores' => $this->errores,
            'erroresTruncados' => $this->erroresTruncados,
        ];
    }
}
