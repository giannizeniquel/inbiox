<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Desacopla `outbox_events.event_type` y `payload.type` del nombre de las clases de evento
 * (contrato de la API, etapa 1: `docs/contrato-api.md`, sección 5).
 *
 * `up()` cambia el valor viejo (nombre de clase, o `ticket.read`) por el nombre interno en
 * todas las filas, de cualquier estado. `down()` aplica el mapa inverso. Cada `CASE` termina
 * en `ELSE <valor actual>`, así que lo que no está en el mapa pasa igual en las dos
 * direcciones. El mapa es literal a propósito: la migración no depende del código actual.
 */
final class Version20261020150000 extends AbstractMigration
{
    /** Valor viejo => nombre interno. */
    private const MAPA = [
        'App\Ticket\TicketCreatedEvent' => 'ticket.creado',
        'App\Ticket\TicketUpdatedEvent' => 'ticket.actualizado',
        'App\Ticket\InboxUpdatedEvent' => 'ticket.bandeja_actualizada',
        'App\Conversacion\MessageCreatedEvent' => 'mensaje.creado',
        'App\Conversacion\MessageUpdatedEvent' => 'mensaje.actualizado',
        'App\Disponibilidad\AgentAvailabilityChangedEvent' => 'agente.disponibilidad_cambiada',
        'App\Disponibilidad\AgentShiftChangedEvent' => 'agente.turno_cambiado',
        'ticket.read' => 'ticket.leido',
    ];

    public function getDescription(): string
    {
        return 'Outbox: internal event names instead of event class names in event_type and payload.type';
    }

    public function up(Schema $schema): void
    {
        foreach (self::sentencias(self::MAPA) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::sentencias(array_flip(self::MAPA)) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
    }

    /**
     * Las sentencias que cambian cada valor del mapa (origen => destino) en `event_type` y,
     * solo donde la clave existe con un valor de origen, en `payload.type`.
     *
     * @param array<string, string> $mapa
     *
     * @return list<array{string, list<string>}>
     */
    private static function sentencias(array $mapa): array
    {
        $casos = implode(' ', array_fill(0, \count($mapa), 'WHEN ? THEN ?'));
        $lista = implode(', ', array_fill(0, \count($mapa), '?'));
        $pares = [];
        foreach ($mapa as $origen => $destino) {
            $pares[] = $origen;
            $pares[] = $destino;
        }
        $origenes = array_keys($mapa);
        $tipoEnPayload = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type'))";

        return [
            [
                "UPDATE outbox_events SET event_type = CASE event_type $casos ELSE event_type END WHERE event_type IN ($lista)",
                [...$pares, ...$origenes],
            ],
            [
                "UPDATE outbox_events SET payload = JSON_SET(payload, '$.type', CASE $tipoEnPayload $casos ELSE $tipoEnPayload END) WHERE $tipoEnPayload IN ($lista)",
                [...$pares, ...$origenes],
            ],
        ];
    }
}
