<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Valores del ticket y de la asignación del contrato de la API, etapa 2
 * (`docs/contrato-api.md`, secciones 3.1 y 4): `tickets.status`, `tickets.priority` y
 * `assignments.type` (con su `DEFAULT`).
 *
 * `up()` cambia el valor viejo por el nuevo y `down()` aplica el mapa inverso. Cada `CASE`
 * termina en `ELSE <valor actual>` y solo se tocan las filas con un valor del mapa, así que
 * los NULL y los valores desconocidos (`urgent`) pasan igual en las dos direcciones. Las
 * comparaciones son binarias, para que un valor con otras mayúsculas (`Active`) tampoco
 * cambie. Los mapas son literales a propósito: la migración no depende del código actual.
 */
final class Version20261020190000 extends AbstractMigration
{
    /** Tabla => columna => (valor viejo => valor nuevo). */
    private const COLUMNAS = [
        'tickets' => [
            'status' => [
                'new' => 'nuevo',
                'active' => 'activo',
                'waiting' => 'en_espera',
                'resolved' => 'resuelto',
                'closed' => 'cerrado',
            ],
            'priority' => [
                'low' => 'baja',
                'medium' => 'media',
                'high' => 'alta',
            ],
        ],
        'assignments' => [
            'type' => [
                'AUTO_ASSIGN' => 'automatica',
                'MANUAL_ASSIGN' => 'manual',
                'REASSIGN' => 'reasignacion',
                'UNASSIGN' => 'desasignacion',
            ],
        ],
    ];

    public function getDescription(): string
    {
        return 'Tickets and assignments: Spanish values for tickets.status, tickets.priority and assignments.type (and its default)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::sentencias(false) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
        $this->addSql("ALTER TABLE assignments CHANGE type type VARCHAR(20) DEFAULT 'manual' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        foreach (self::sentencias(true) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
        $this->addSql("ALTER TABLE assignments CHANGE type type VARCHAR(20) DEFAULT 'MANUAL_ASSIGN' NOT NULL");
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
