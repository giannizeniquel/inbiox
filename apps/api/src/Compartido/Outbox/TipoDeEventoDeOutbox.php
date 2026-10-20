<?php

declare(strict_types=1);

namespace App\Compartido\Outbox;

/**
 * Los nombres internos que se guardan en `outbox_events.event_type` (y en `payload.type`).
 *
 * Son un formato persistido y no dependen del nombre de las clases de evento, que se
 * pueden renombrar libremente. No viajan por la red: `TipoDeEventoMapper` los traduce
 * al tipo de Mercure.
 */
enum TipoDeEventoDeOutbox: string
{
    case TICKET_CREADO = 'ticket.creado';
    case TICKET_ACTUALIZADO = 'ticket.actualizado';
    case BANDEJA_DE_TICKET_ACTUALIZADA = 'ticket.bandeja_actualizada';
    case MENSAJE_CREADO = 'mensaje.creado';
    case MENSAJE_ACTUALIZADO = 'mensaje.actualizado';
    case DISPONIBILIDAD_DE_AGENTE_CAMBIADA = 'agente.disponibilidad_cambiada';
    case TURNO_DE_AGENTE_CAMBIADO = 'agente.turno_cambiado';
    case TICKET_LEIDO = 'ticket.leido';

    /**
     * El valor que se guardaba antes de los nombres internos: el nombre de la clase de evento,
     * o `ticket.read` para el ticket leído. Se acepta al leer mientras queden filas viejas;
     * se quita en la etapa 3 del contrato (`docs/contrato-api.md`, sección 6).
     *
     * Son literales a propósito: renombrar una clase de evento no debe cambiar el alias.
     */
    public function valorLegado(): string
    {
        return match ($this) {
            self::TICKET_CREADO => 'App\Ticket\TicketCreatedEvent',
            self::TICKET_ACTUALIZADO => 'App\Ticket\TicketUpdatedEvent',
            self::BANDEJA_DE_TICKET_ACTUALIZADA => 'App\Ticket\InboxUpdatedEvent',
            self::MENSAJE_CREADO => 'App\Conversacion\MessageCreatedEvent',
            self::MENSAJE_ACTUALIZADO => 'App\Conversacion\MessageUpdatedEvent',
            self::DISPONIBILIDAD_DE_AGENTE_CAMBIADA => 'App\Disponibilidad\AgentAvailabilityChangedEvent',
            self::TURNO_DE_AGENTE_CAMBIADO => 'App\Disponibilidad\AgentShiftChangedEvent',
            self::TICKET_LEIDO => 'ticket.read',
        };
    }

    /**
     * Todos los valores guardados que representan este tipo: el nombre interno y su alias legado.
     *
     * @return list<string>
     */
    public function valoresGuardados(): array
    {
        return [$this->value, $this->valorLegado()];
    }

    /**
     * Resuelve un valor guardado, sea el nombre interno o su alias legado.
     */
    public static function desdeValorGuardado(string $valor): ?self
    {
        $tipo = self::tryFrom($valor);
        if ($tipo !== null) {
            return $tipo;
        }

        foreach (self::cases() as $caso) {
            if ($caso->valorLegado() === $valor) {
                return $caso;
            }
        }

        return null;
    }
}
