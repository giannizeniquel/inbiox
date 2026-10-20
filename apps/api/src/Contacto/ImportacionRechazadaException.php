<?php

declare(strict_types=1);

namespace App\Contacto;

/**
 * Rechazo de todo el archivo en la importación de contactos por CSV.
 *
 * Se lanza cuando el propio archivo subido no se puede usar (falta, es demasiado grande, tipo
 * incorrecto, falta una columna obligatoria, está vacío o supera el límite de filas) — nunca por
 * fallos de validación por fila, que se informan en el resultado de éxito
 * parcial. El controller traduce `getMotivo()` a HTTP 400.
 */
class ImportacionRechazadaException extends \RuntimeException
{
    public function __construct(
        private readonly string $motivo,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getMotivo(): string
    {
        return $this->motivo;
    }
}
