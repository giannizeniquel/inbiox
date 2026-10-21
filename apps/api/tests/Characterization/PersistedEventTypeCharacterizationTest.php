<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Compartido\Realtime\TipoDeEventoMapper;
use App\Compartido\Realtime\EventoEnTiempoRealFactory;
use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\MessageCreatedEvent;
use App\Conversacion\MessageUpdatedEvent;
use App\Ticket\EstadoDeTicket;
use App\Ticket\InboxUpdatedEvent;
use App\Ticket\TicketCreatedEvent;
use App\Ticket\TicketUpdatedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

/**
 * Pins the internal names stored in outbox_events.event_type and the Mercure
 * message built from a row with each stored name.
 *
 * The rows keep the values written before the API contract (`inbound`, `failed`,
 * `created`); the Mercure message carries the contract values.
 *
 * The names are string literals on purpose: they are a persisted format. The
 * legacy values (event class names and 'ticket.read') were removed in stage 3
 * of the API contract and are no longer outbox names.
 */
final class PersistedEventTypeCharacterizationTest extends TestCase
{
    public function testInternalNamesAreStable(): void
    {
        self::assertSame(
            [
                'ticket.creado',
                'ticket.actualizado',
                'ticket.bandeja_actualizada',
                'mensaje.creado',
                'mensaje.actualizado',
                'agente.disponibilidad_cambiada',
                'agente.turno_cambiado',
                'ticket.leido',
            ],
            array_map(static fn (TipoDeEventoDeOutbox $tipo): string => $tipo->value, TipoDeEventoDeOutbox::cases()),
        );
    }

    public function testDomainEventsCarryTheInternalName(): void
    {
        $id = new Ulid();
        $at = new \DateTimeImmutable('2026-10-01T10:00:00+00:00');

        self::assertSame('ticket.creado', (new TicketCreatedEvent($id, 1, $at, 1, 1, EstadoDeTicket::NUEVO, 2))->type);
        self::assertSame('ticket.actualizado', (new TicketUpdatedEvent($id, 1, $at, 1, 1, null, 5))->type);
        self::assertSame('ticket.bandeja_actualizada', (new InboxUpdatedEvent($id, 1, $at, 1, 1))->type);
        self::assertSame('mensaje.creado', (new MessageCreatedEvent($id, 1, $at, 1, 1, DireccionDeMensaje::ENTRANTE, 9))->type);
        self::assertSame('mensaje.actualizado', (new MessageUpdatedEvent($id, 1, $at, 1, 1, 9, 'enviado'))->type);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, array<string, mixed>}>
     */
    public static function storedRows(): iterable
    {
        yield 'ticket created' => [
            'ticket.creado',
            'App\Ticket\TicketCreatedEvent',
            ['event_id' => 'evt-1', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'contact_id' => 7, 'conversation_id' => 8, 'status' => 'new', 'priority' => 2, 'created_at' => '2026-10-01T10:00:00+00:00'],
            ['tipoDeEvento' => 'ticket.creado', 'payload' => ['ticketId' => 100, 'contactoId' => 7, 'conversacionId' => 8, 'estado' => 'nuevo', 'prioridad' => 2, 'creadoAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'ticket status changed' => [
            'ticket.actualizado',
            'App\Ticket\TicketUpdatedEvent',
            ['event_id' => 'evt-2', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'previous_status' => 'new', 'status' => 'active', 'changed_at' => '2026-10-01T10:00:00+00:00'],
            ['tipoDeEvento' => 'ticket.estado_cambiado', 'payload' => ['ticketId' => 100, 'estadoAnterior' => 'nuevo', 'estadoNuevo' => 'activo', 'cambiadoAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'ticket assigned' => [
            'ticket.actualizado',
            'App\Ticket\TicketUpdatedEvent',
            ['event_id' => 'evt-3', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'ownerAgentId' => 5, 'assignee_name' => 'Ana', 'assignment_type' => 'automatica', 'assigned_at' => '2026-10-01T10:00:00+00:00'],
            ['tipoDeEvento' => 'ticket.asignado', 'payload' => ['ticketId' => 100, 'responsableId' => 5, 'responsableNombre' => 'Ana', 'tipoDeAsignacion' => 'automatica', 'asignadoAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'ticket assigned by assignee_id' => [
            'ticket.actualizado',
            'App\Ticket\TicketUpdatedEvent',
            ['event_id' => 'evt-3b', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'assignee_id' => 6],
            ['tipoDeEvento' => 'ticket.asignado', 'payload' => ['ticketId' => 100, 'responsableId' => 6, 'responsableNombre' => null, 'tipoDeAsignacion' => 'manual', 'asignadoAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'message added' => [
            'mensaje.creado',
            'App\Conversacion\MessageCreatedEvent',
            ['event_id' => 'evt-4', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'message_id' => 9, 'content' => 'hola', 'direction' => 'inbound', 'sender_name' => 'Juan', 'created_at' => '2026-10-01T10:00:00+00:00', 'external_id' => 'wamid.1', 'phone_number_id' => '123', 'media' => null],
            ['tipoDeEvento' => 'mensaje.agregado', 'payload' => ['mensajeId' => 9, 'ticketId' => 100, 'contenido' => 'hola', 'direccion' => 'entrante', 'remitenteNombre' => 'Juan', 'creadoAt' => '2026-10-01T10:00:00+00:00', 'externalId' => 'wamid.1', 'phoneNumberId' => '123', 'adjunto' => null]],
        ];
        yield 'message updated' => [
            'mensaje.actualizado',
            'App\Conversacion\MessageUpdatedEvent',
            ['event_id' => 'evt-5', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'message_id' => 9, 'delivery_status' => 'failed', 'failed_reason' => 'expired'],
            ['tipoDeEvento' => 'mensaje.actualizado', 'payload' => ['mensajeId' => 9, 'ticketId' => 100, 'estadoDeEntrega' => 'fallido', 'motivoDeFallo' => 'expired', 'actualizadoAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'agent availability changed' => [
            'agente.disponibilidad_cambiada',
            'App\Disponibilidad\AgentAvailabilityChangedEvent',
            ['event_id' => 'evt-6', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'agent_id' => 5, 'is_available' => true, 'override_active' => true, 'current_load' => 2, 'max_capacity' => 4, 'timestamp' => '2026-10-01T10:00:00+00:00'],
            ['tipoDeEvento' => 'agente.disponibilidad_cambiada', 'payload' => ['agenteId' => 5, 'disponible' => true, 'disponibilidadManualActiva' => true, 'cargaActual' => 2, 'capacidadMaxima' => 4, 'ocurridoAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'agent shift changed' => [
            'agente.turno_cambiado',
            'App\Disponibilidad\AgentShiftChangedEvent',
            ['event_id' => 'evt-7', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'agent_id' => 5, 'shift_id' => 3, 'action' => 'created', 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'timestamp' => '2026-10-01T10:00:00+00:00'],
            ['tipoDeEvento' => 'agente.turno_cambiado', 'payload' => ['agenteId' => 5, 'turnoId' => 3, 'accion' => 'creado', 'diaDeLaSemana' => 1, 'horaDeInicio' => '09:00', 'horaDeFin' => '17:00', 'ocurridoAt' => '2026-10-01T10:00:00+00:00']],
        ];
        yield 'ticket read' => [
            'ticket.leido',
            'ticket.read',
            ['event_id' => 'evt-8', 'occurred_at' => '2026-10-01T10:00:00+00:00', 'eventType' => 'ticket.read', 'ticketId' => 100],
            ['tipoDeEvento' => 'ticket.leido', 'payload' => ['ticketId' => 100]],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $expected
     */
    #[DataProvider('storedRows')]
    public function testStoredRowBuildsTheSameMercureMessage(string $internalName, string $legacyName, array $stored, array $expected): void
    {
        $message = [
            'eventoId' => $stored['event_id'],
            'sequence' => 1,
            'ocurridoAt' => '2026-10-01T10:00:00+00:00',
            'tipoDeEvento' => $expected['tipoDeEvento'],
            'ticketId' => '100',
            'payload' => $expected['payload'],
        ];

        self::assertSame($message, $this->build($internalName, $stored));
    }

    /**
     * A legacy stored value fails and dead-letters like any unknown name.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $expected
     */
    #[DataProvider('storedRows')]
    public function testLegacyNameIsNotAnOutboxName(string $internalName, string $legacyName, array $stored, array $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build($legacyName, $stored);
    }

    /**
     * A stored value that is already a Mercure type is not an outbox name: it
     * fails and dead-letters.
     */
    public function testStoredMercureTypeIsNotAnOutboxName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build('ticket.created', ['event_id' => 'evt-10']);
    }

    /**
     * InboxUpdatedEvent has no Mercure type: building it fails, so the
     * publisher retries the row until it ends in dead letter.
     */
    #[DataProvider('inboxUpdatedNames')]
    public function testInboxUpdatedHasNoMercureType(string $storedEventType): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build($storedEventType, ['event_id' => 'evt-9']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inboxUpdatedNames(): iterable
    {
        yield 'internal name' => ['ticket.bandeja_actualizada'];
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function build(string $storedEventType, array $stored): array
    {
        $row = new EventoOutbox(
            aggregateType: 'ticket',
            aggregateId: 100,
            topic: 'tickets',
            tipoDeEvento: $storedEventType,
            payload: $stored,
            sequence: 1,
        );

        return (new EventoEnTiempoRealFactory(new TipoDeEventoMapper()))->build($row);
    }
}
