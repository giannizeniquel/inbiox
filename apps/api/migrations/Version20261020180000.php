<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Valores de `messages` del contrato de la API, etapa 2 (`docs/contrato-api.md`, secciones
 * 3.1 y 4): dirección, remitente, estado de entrega (con su `DEFAULT`) y el tipo del
 * adjunto (`media.kind`, con `JSON_SET`; las claves del adjunto no cambian, D8).
 *
 * `up()` cambia el valor viejo por el nuevo y `down()` aplica el mapa inverso. Cada `CASE`
 * termina en `ELSE <valor actual>` y solo se tocan las filas con un valor del mapa, así que
 * los NULL, las filas sin `kind` y los valores desconocidos pasan igual en las dos
 * direcciones. Las comparaciones son binarias, para que un valor con otras mayúsculas
 * (`Inbound`) tampoco cambie. Los mapas son literales a propósito: la migración no depende
 * del código actual.
 */
final class Version20261020180000 extends AbstractMigration
{
    /** Columna => (valor viejo => valor nuevo). */
    private const COLUMNAS = [
        'direction' => [
            'inbound' => 'entrante',
            'outbound' => 'saliente',
        ],
        'sender_type' => [
            'agent' => 'agente',
            'automatic' => 'automatico',
            'contact' => 'contacto',
        ],
        'delivery_status' => [
            'sent' => 'enviado',
            'delivered' => 'entregado',
            'read' => 'leido',
            'failed' => 'fallido',
        ],
    ];

    /** Tipo del adjunto: valor viejo => valor nuevo (`video` y `audio` no cambian). */
    private const TIPOS_DE_ADJUNTO = [
        'image' => 'imagen',
        'document' => 'documento',
    ];

    public function getDescription(): string
    {
        return 'Messages: Spanish values for direction, sender_type, delivery_status (and its default) and media.kind';
    }

    public function up(Schema $schema): void
    {
        foreach (self::sentencias(false) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
        $this->addSql("ALTER TABLE messages CHANGE delivery_status delivery_status VARCHAR(20) DEFAULT 'enviado' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        foreach (self::sentencias(true) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
        $this->addSql("ALTER TABLE messages CHANGE delivery_status delivery_status VARCHAR(20) DEFAULT 'sent' NOT NULL");
    }

    /**
     * Las sentencias que cambian cada valor de los mapas (viejo => nuevo, o al revés).
     *
     * @return list<array{string, list<string>}>
     */
    private static function sentencias(bool $inverso): array
    {
        $sentencias = [];
        foreach (self::COLUMNAS as $columna => $mapa) {
            [$casos, $lista, $params] = self::caseYLista($inverso ? array_flip($mapa) : $mapa);
            $sentencias[] = [
                "UPDATE messages SET $columna = CASE CAST($columna AS BINARY) $casos ELSE $columna END WHERE CAST($columna AS BINARY) IN ($lista)",
                $params,
            ];
        }

        [$casos, $lista, $params] = self::caseYLista($inverso ? array_flip(self::TIPOS_DE_ADJUNTO) : self::TIPOS_DE_ADJUNTO);
        $tipo = "JSON_UNQUOTE(JSON_EXTRACT(media, '$.kind'))";
        $sentencias[] = [
            "UPDATE messages SET media = JSON_SET(media, '$.kind', CASE CAST($tipo AS BINARY) $casos ELSE $tipo END) WHERE JSON_TYPE(JSON_EXTRACT(media, '$.kind')) = 'STRING' AND CAST($tipo AS BINARY) IN ($lista)",
            $params,
        ];

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
