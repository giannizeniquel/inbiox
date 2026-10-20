<?php

declare(strict_types=1);

namespace App\Contacto;

/**
 * Regla compartida de normalización de teléfonos.
 *
 * La usan tanto la creación de un solo contacto (CreateContactoUseCase) como la importación
 * masiva por CSV (ImportarContactosDesdeCsvUseCase) para que la deduplicación dentro del archivo y los contactos
 * persistidos siempre coincidan en qué significa "el mismo teléfono".
 */
final class NormalizadorDeTelefono
{
    /**
     * @throws \InvalidArgumentException cuando el teléfono normalizado tiene menos de 10 dígitos
     */
    public static function normalizar(string $raw): string
    {
        $normalized = '+' . preg_replace('/[^0-9]/', '', $raw);

        if (strlen($normalized) < 10) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        return $normalized;
    }
}
