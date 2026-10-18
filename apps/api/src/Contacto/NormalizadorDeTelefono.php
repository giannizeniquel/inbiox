<?php

declare(strict_types=1);

namespace App\Contacto;

/**
 * Shared phone normalization rule.
 *
 * Used by both single-contact creation (CreateContactUseCase) and bulk CSV
 * import (ImportContactsFromCsvUseCase) so in-file dedupe and persisted
 * contacts always agree on what "the same phone" means.
 */
final class NormalizadorDeTelefono
{
    /**
     * @throws \InvalidArgumentException when the normalized phone has fewer than 10 digits
     */
    public static function normalize(string $raw): string
    {
        $normalized = '+' . preg_replace('/[^0-9]/', '', $raw);

        if (strlen($normalized) < 10) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        return $normalized;
    }
}
