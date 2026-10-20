<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Valores del contacto y de los eventos de ticket del contrato de la API, etapa 2
 * (`docs/contrato-api.md`, secciones 3.1 y 4): `contacts.status`, `contacts.channel` y
 * `ticket_events.type`. Ninguna de las tres columnas tiene `DEFAULT`.
 *
 * `up()` cambia el valor viejo por el nuevo y `down()` aplica el mapa inverso. Cada `CASE`
 * termina en `ELSE <valor actual>` y solo se tocan las filas con un valor del mapa, así que
 * los NULL y los valores desconocidos (`auto_closed`) pasan igual en las dos direcciones. Las
 * comparaciones son binarias, para que un valor con otras mayúsculas (`Active`) tampoco
 * cambie. Los mapas son literales a propósito: la migración no depende del código actual.
 */
final class Version20261020200000 extends AbstractMigration
{
    /** Tabla => columna => (valor viejo => valor nuevo). */
    private const COLUMNAS = [
        'contacts' => [
            'status' => [
                'active' => 'activo',
                'inactive' => 'inactivo',
            ],
            'channel' => [
                'other' => 'otro',
            ],
        ],
        'ticket_events' => [
            'type' => [
                'window_expired_closed' => 'ventana_vencida_cerrado',
                'window_reopened' => 'ventana_reabierta',
                'manual_resolved_owner' => 'resuelto_manual_por_responsable',
                'manual_resolved_override' => 'resuelto_manual_forzado',
                'manual_resolved_unowned' => 'resuelto_manual_sin_responsable',
                'manual_closed_owner' => 'cerrado_manual_por_responsable',
                'manual_closed_override' => 'cerrado_manual_forzado',
                'manual_closed_unowned' => 'cerrado_manual_sin_responsable',
                'auto_reopened' => 'reapertura_automatica',
                'tag_added' => 'etiqueta_agregada',
            ],
        ],
    ];

    public function getDescription(): string
    {
        return 'Contacts and ticket events: Spanish values for contacts.status, contacts.channel and ticket_events.type';
    }

    public function up(Schema $schema): void
    {
        foreach (self::sentencias(false) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::sentencias(true) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
    }

    /**
     * Las sentencias que cambian cada valor de los mapas (viejo => nuevo, o al revés).
     *
     * @return list<array{string, list<string>}>
     */
    private static function sentencias(bool $inverso): array
    {
        $sentencias = [];
        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna => $mapa) {
                [$casos, $lista, $params] = self::caseYLista($inverso ? array_flip($mapa) : $mapa);
                $sentencias[] = [
                    "UPDATE $tabla SET $columna = CASE CAST($columna AS BINARY) $casos ELSE $columna END WHERE CAST($columna AS BINARY) IN ($lista)",
                    $params,
                ];
            }
        }

        return $sentencias;
    }

    /**
     * Los `WHEN ? THEN ?` del `CASE`, los `?` del `IN` y sus parámetros, en ese orden.
     *
     * @param array<string, string> $mapa origen => destino
     *
     * @return array{string, string, list<string>}
     */
    private static function caseYLista(array $mapa): array
    {
        $pares = [];
        foreach ($mapa as $origen => $destino) {
            $pares[] = $origen;
            $pares[] = $destino;
        }

        return [
            implode(' ', array_fill(0, \count($mapa), 'WHEN ? THEN ?')),
            implode(', ', array_fill(0, \count($mapa), '?')),
            [...$pares, ...array_keys($mapa)],
        ];
    }
}
