<?php

declare(strict_types=1);

namespace App\Conversacion;

/**
 * Traduce las claves del adjunto guardado (`messages.media` y la copia del payload del
 * outbox) a las claves del contrato de la API. Las claves guardadas quedan en inglés
 * (`docs/contrato-api.md`, D8); este es el único lugar que las traduce al leer.
 */
final class ClavesDeAdjunto
{
    /** Clave guardada => clave del contrato. `key`, `mimeType` y `providerId` no cambian. */
    private const CLAVES = [
        'filename' => 'nombreDeArchivo',
        'size' => 'tamanio',
        'caption' => 'leyenda',
        'kind' => 'tipo',
    ];

    /**
     * @param array<string, mixed>|null $adjunto el adjunto tal como está guardado
     *
     * @return array<string, mixed>|null el mismo adjunto, en el mismo orden, con las claves del contrato
     */
    public static function aContrato(?array $adjunto): ?array
    {
        if ($adjunto === null) {
            return null;
        }

        $traducido = [];
        foreach ($adjunto as $clave => $valor) {
            $traducido[self::CLAVES[$clave] ?? $clave] = $valor;
        }

        return $traducido;
    }
}
