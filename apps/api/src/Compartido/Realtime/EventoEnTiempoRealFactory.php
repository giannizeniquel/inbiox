<?php

namespace App\Compartido\Realtime;

use App\Compartido\Outbox\EventoOutbox;
use App\Conversacion\ClavesDeAdjunto;
use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\EstadoDeEntrega;
use App\Conversacion\TipoDeAdjunto;

/**
 * Arma el mensaje de Mercure (contrato de la API, `docs/contrato-api.md`, sección 3.2 y
 * anexo A.12) a partir de una fila del outbox.
 *
 * Lee el payload guardado, cuyas claves no cambian (snake_case, como lo escriben los casos
 * de uso), y arma las claves del contrato en camelCase. La columna payload no se toca: solo
 * la salida de build() se publica en Mercure.
 *
 * Las filas escritas antes del contrato traen valores viejos (`inbound`, `sent`, `image`,
 * `created`…): se traducen al leerlas (sección 5, punto 5).
 */
final readonly class EventoEnTiempoRealFactory
{
    /** Valor viejo guardado => valor del contrato, por campo del payload. */
    private const VALORES_VIEJOS = [
        'direccion' => [
            'inbound' => DireccionDeMensaje::ENTRANTE->value,
            'outbound' => DireccionDeMensaje::SALIENTE->value,
        ],
        'estadoDeEntrega' => [
            'sent' => EstadoDeEntrega::ENVIADO->value,
            'delivered' => EstadoDeEntrega::ENTREGADO->value,
            'read' => EstadoDeEntrega::LEIDO->value,
            'failed' => EstadoDeEntrega::FALLIDO->value,
        ],
        'tipoDeAdjunto' => [
            'image' => TipoDeAdjunto::IMAGEN->value,
            'document' => TipoDeAdjunto::DOCUMENTO->value,
        ],
        // Los turnos guardan la acción en inglés; viaja en español.
        'accion' => [
            'created' => 'creado',
            'deleted' => 'borrado',
        ],
    ];

    public function __construct(private TipoDeEventoMapper $mapper) {}

    /**
     * Arma el mensaje de Mercure de una fila del outbox.
     *
     * @return array{eventoId: string, sequence: int, ocurridoAt: string, tipoDeEvento: string, ticketId: string, payload: array<string, mixed>}
     */
    public function build(EventoOutbox $evento): array
    {
        $stored    = $evento->getPayload();
        $enumValue = $this->mapper->aTipoEnTiempoReal($evento->getTipoDeEvento(), $stored);

        return [
            'eventoId'     => (string) ($stored['event_id'] ?? $evento->getId()),
            'sequence'     => $evento->getSequence(),
            'ocurridoAt'   => $stored['occurred_at'] ?? $evento->getCreadoAt()->format(\DateTimeInterface::RFC3339),
            'tipoDeEvento' => $enumValue->value,
            'ticketId'     => (string) $evento->getAggregateId(),
            'payload'      => $this->armarPayload($enumValue, $stored, (int) $evento->getAggregateId()),
        ];
    }

    /**
     * Arma la sección tipada del payload según el tipo de evento.
     *
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function armarPayload(TipoDeEventoEnTiempoReal $type, array $s, int $ticketId): array
    {
        return match ($type) {
            TipoDeEventoEnTiempoReal::TICKET_CREADO => [
                'ticketId'       => $ticketId,
                'contactoId'     => (int) ($s['contact_id'] ?? 0),
                'conversacionId' => (int) ($s['conversation_id'] ?? 0),
                'estado'         => $s['status'] ?? '',
                'prioridad'      => (int) ($s['priority'] ?? 0),
                'creadoAt'       => $s['created_at'] ?? $s['occurred_at'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::MENSAJE_AGREGADO => [
                'mensajeId'       => (int) ($s['message_id'] ?? 0),
                'ticketId'        => $ticketId,
                'contenido'       => $s['content'] ?? '',
                'direccion'       => self::valorActual('direccion', $s['direction'] ?? ''),
                'remitenteNombre' => $s['sender_name'] ?? null,
                'creadoAt'        => $s['created_at'] ?? $s['occurred_at'] ?? '',
                'externalId'      => $s['external_id'] ?? null,
                'phoneNumberId'   => $s['phone_number_id'] ?? null,
                'adjunto'         => self::adjunto($s['media'] ?? null),
            ],
            TipoDeEventoEnTiempoReal::ESTADO_DE_TICKET_CAMBIADO => [
                'ticketId'       => $ticketId,
                'estadoAnterior' => $s['previous_status'] ?? null,
                'estadoNuevo'    => $s['status'] ?? '',
                'cambiadoAt'     => $s['changed_at'] ?? $s['occurred_at'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::TICKET_ASIGNADO => [
                'ticketId'          => $ticketId,
                'responsableId'     => (int) ($s['assignee_id'] ?? $s['ownerAgentId'] ?? 0),
                'responsableNombre' => $s['assignee_name'] ?? null,
                'tipoDeAsignacion'  => $s['assignment_type'] ?? 'MANUAL_ASSIGN',
                'asignadoAt'        => $s['assigned_at'] ?? $s['occurred_at'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::DISPONIBILIDAD_DE_AGENTE_CAMBIADA => [
                'agenteId'                   => (int) ($s['agent_id'] ?? 0),
                'disponible'                 => (bool) ($s['is_available'] ?? false),
                'disponibilidadManualActiva' => (bool) ($s['override_active'] ?? false),
                'cargaActual'                => (int) ($s['current_load'] ?? 0),
                'capacidadMaxima'            => (int) ($s['max_capacity'] ?? 0),
                'ocurridoAt'                 => $s['timestamp'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::TURNO_DE_AGENTE_CAMBIADO => [
                'agenteId'      => (int) ($s['agent_id'] ?? 0),
                'turnoId'       => (int) ($s['shift_id'] ?? 0),
                'accion'        => self::valorActual('accion', $s['action'] ?? ''),
                'diaDeLaSemana' => (int) ($s['day_of_week'] ?? 0),
                'horaDeInicio'  => $s['start_time'] ?? '',
                'horaDeFin'     => $s['end_time'] ?? '',
                'ocurridoAt'    => $s['timestamp'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::MENSAJE_ACTUALIZADO => [
                'mensajeId'       => (int) ($s['message_id'] ?? 0),
                'ticketId'        => $ticketId,
                'estadoDeEntrega' => self::valorActual('estadoDeEntrega', $s['delivery_status'] ?? null),
                'motivoDeFallo'   => $s['failed_reason'] ?? null,
                'actualizadoAt'   => $s['occurred_at'] ?? '',
            ],
            TipoDeEventoEnTiempoReal::TICKET_LEIDO => [
                'ticketId' => $ticketId,
            ],
        };
    }

    /**
     * El adjunto guardado con las claves del contrato y el tipo traducido si es viejo.
     */
    private static function adjunto(mixed $guardado): mixed
    {
        if (!\is_array($guardado)) {
            return $guardado;
        }

        $adjunto = ClavesDeAdjunto::aContrato($guardado);
        if (\array_key_exists('tipo', $adjunto)) {
            $adjunto['tipo'] = self::valorActual('tipoDeAdjunto', $adjunto['tipo']);
        }

        return $adjunto;
    }

    /**
     * El valor del contrato para un valor guardado: el viejo se traduce y cualquier otro pasa igual.
     */
    private static function valorActual(string $campo, mixed $valor): mixed
    {
        return \is_string($valor) ? (self::VALORES_VIEJOS[$campo][$valor] ?? $valor) : $valor;
    }
}
