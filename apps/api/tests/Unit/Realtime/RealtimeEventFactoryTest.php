<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Compartido\Realtime\EventoEnTiempoRealFactory;
use App\Compartido\Realtime\TipoDeEventoMapper;
use PHPUnit\Framework\TestCase;

/**
 * EventoEnTiempoRealFactory: the Mercure message for each event type, per the API
 * contract (`docs/contrato-api.md`, section 3.2 and annex A.12).
 *
 * The stored payload keeps its keys (snake_case, as written by the use cases); the
 * factory builds the camelCase wire keys and translates the old stored values
 * (`inbound`, `sent`, `image`, `created`…) of rows written before the contract
 * (section 5, point 5).
 */
class RealtimeEventFactoryTest extends TestCase
{
    private EventoEnTiempoRealFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EventoEnTiempoRealFactory(new TipoDeEventoMapper());
    }

    public function testEnvelopeKeys(): void
    {
        $result = $this->build('ticket', 100, TipoDeEventoDeOutbox::TICKET_LEIDO->value, [
            'event_id' => 'evt-000',
            'occurred_at' => '2026-06-14T15:30:00Z',
        ], 4);

        self::assertSame([
            'eventoId' => 'evt-000',
            'sequence' => 4,
            'ocurridoAt' => '2026-06-14T15:30:00Z',
            'tipoDeEvento' => 'ticket.leido',
            'ticketId' => '100',
            'payload' => ['ticketId' => 100],
        ], $result);
    }

    public function testTicketCreado(): void
    {
        $result = $this->build('ticket', 100, TipoDeEventoDeOutbox::TICKET_CREADO->value, [
            'contact_id' => 7,
            'conversation_id' => 9,
            'status' => 'nuevo',
            'priority' => 'media',
            'created_at' => '2026-06-14T15:30:00Z',
        ]);

        self::assertSame('ticket.creado', $result['tipoDeEvento']);
        // La prioridad sale como entero (hallazgo 13 del contrato, se decide aparte).
        self::assertSame([
            'ticketId' => 100,
            'contactoId' => 7,
            'conversacionId' => 9,
            'estado' => 'nuevo',
            'prioridad' => 0,
            'creadoAt' => '2026-06-14T15:30:00Z',
        ], $result['payload']);
    }

    public function testTicketCreadoTranslatesOldStoredStatus(): void
    {
        $result = $this->build('ticket', 100, 'App\Ticket\TicketCreatedEvent', ['status' => 'new']);

        self::assertSame('nuevo', $result['payload']['estado']);
    }

    public function testMensajeAgregadoWithAttachment(): void
    {
        $result = $this->build('ticket', 100, TipoDeEventoDeOutbox::MENSAJE_CREADO->value, [
            'message_id' => 55,
            'content' => 'hola',
            'direction' => 'entrante',
            'sender_name' => 'Ana',
            'created_at' => '2026-06-14T15:30:00Z',
            'external_id' => 'wamid.1',
            'phone_number_id' => '123',
            'media' => [
                'key' => 'default/1/a.jpg',
                'mimeType' => 'image/jpeg',
                'filename' => 'a.jpg',
                'size' => 10,
                'kind' => 'imagen',
                'providerId' => 'm-1',
                'caption' => 'foto',
            ],
        ]);

        self::assertSame('mensaje.agregado', $result['tipoDeEvento']);
        self::assertSame([
            'mensajeId' => 55,
            'ticketId' => 100,
            'contenido' => 'hola',
            'direccion' => 'entrante',
            'remitenteNombre' => 'Ana',
            'creadoAt' => '2026-06-14T15:30:00Z',
            'externalId' => 'wamid.1',
            'phoneNumberId' => '123',
            'adjunto' => [
                'key' => 'default/1/a.jpg',
                'mimeType' => 'image/jpeg',
                'nombreDeArchivo' => 'a.jpg',
                'tamanio' => 10,
                'tipo' => 'imagen',
                'providerId' => 'm-1',
                'leyenda' => 'foto',
            ],
        ], $result['payload']);
    }

    public function testMensajeAgregadoTranslatesOldStoredValues(): void
    {
        $result = $this->build('ticket', 100, 'App\Conversacion\MessageCreatedEvent', [
            'message_id' => 56,
            'direction' => 'outbound',
            'media' => ['key' => 'k', 'kind' => 'document', 'filename' => 'f.pdf'],
        ]);

        self::assertSame('saliente', $result['payload']['direccion']);
        self::assertSame(['key' => 'k', 'tipo' => 'documento', 'nombreDeArchivo' => 'f.pdf'], $result['payload']['adjunto']);

        $entrante = $this->build('ticket', 100, TipoDeEventoDeOutbox::MENSAJE_CREADO->value, ['direction' => 'inbound']);
        self::assertSame('entrante', $entrante['payload']['direccion']);
        self::assertNull($entrante['payload']['adjunto']);
    }

    public function testEstadoDeTicketCambiado(): void
    {
        $result = $this->build('ticket', 100, TipoDeEventoDeOutbox::TICKET_ACTUALIZADO->value, [
            'previous_status' => 'nuevo',
            'status' => 'activo',
            'changed_at' => '2026-06-14T15:30:00Z',
        ]);

        self::assertSame('ticket.estado_cambiado', $result['tipoDeEvento']);
        self::assertSame([
            'ticketId' => 100,
            'estadoAnterior' => 'nuevo',
            'estadoNuevo' => 'activo',
            'cambiadoAt' => '2026-06-14T15:30:00Z',
        ], $result['payload']);
    }

    public function testEstadoDeTicketCambiadoTranslatesOldStoredValues(): void
    {
        $esperados = ['new' => 'nuevo', 'active' => 'activo', 'waiting' => 'en_espera', 'resolved' => 'resuelto', 'closed' => 'cerrado'];
        foreach ($esperados as $viejo => $nuevo) {
            $result = $this->build('ticket', 100, 'App\Ticket\TicketUpdatedEvent', ['previous_status' => $viejo, 'status' => $viejo]);
            self::assertSame($nuevo, $result['payload']['estadoAnterior'], $viejo);
            self::assertSame($nuevo, $result['payload']['estadoNuevo'], $viejo);
        }

        $sinAnterior = $this->build('ticket', 100, TipoDeEventoDeOutbox::TICKET_ACTUALIZADO->value, ['status' => 'resuelto']);
        self::assertNull($sinAnterior['payload']['estadoAnterior']);
    }

    public function testTicketAsignado(): void
    {
        $result = $this->build('ticket', 100, TipoDeEventoDeOutbox::TICKET_ACTUALIZADO->value, [
            'assignee_id' => 5,
            'assignee_name' => 'Ana',
            'assignment_type' => 'automatica',
            'assigned_at' => '2026-06-14T15:30:00Z',
        ]);

        self::assertSame('ticket.asignado', $result['tipoDeEvento']);
        self::assertSame([
            'ticketId' => 100,
            'responsableId' => 5,
            'responsableNombre' => 'Ana',
            'tipoDeAsignacion' => 'automatica',
            'asignadoAt' => '2026-06-14T15:30:00Z',
        ], $result['payload']);

        // Sin tipo guardado, el de siempre.
        $porDefecto = $this->build('ticket', 101, 'App\Ticket\TicketUpdatedEvent', ['ownerAgentId' => 3]);
        self::assertSame('ticket.asignado', $porDefecto['tipoDeEvento']);
        self::assertSame(3, $porDefecto['payload']['responsableId']);
        self::assertSame('manual', $porDefecto['payload']['tipoDeAsignacion']);
    }

    public function testTicketAsignadoTranslatesOldStoredType(): void
    {
        $esperados = ['AUTO_ASSIGN' => 'automatica', 'MANUAL_ASSIGN' => 'manual', 'REASSIGN' => 'reasignacion', 'UNASSIGN' => 'desasignacion'];
        foreach ($esperados as $viejo => $nuevo) {
            $result = $this->build('ticket', 100, 'App\Ticket\TicketUpdatedEvent', ['ownerAgentId' => 3, 'assignment_type' => $viejo]);
            self::assertSame($nuevo, $result['payload']['tipoDeAsignacion'], $viejo);
        }
    }

    public function testMensajeActualizado(): void
    {
        $result = $this->build('ticket', 100, TipoDeEventoDeOutbox::MENSAJE_ACTUALIZADO->value, [
            'message_id' => 55,
            'delivery_status' => 'fallido',
            'failed_reason' => 'Message undeliverable',
            'occurred_at' => '2026-06-14T15:30:00Z',
        ]);

        self::assertSame('mensaje.actualizado', $result['tipoDeEvento']);
        self::assertSame([
            'mensajeId' => 55,
            'ticketId' => 100,
            'estadoDeEntrega' => 'fallido',
            'motivoDeFallo' => 'Message undeliverable',
            'actualizadoAt' => '2026-06-14T15:30:00Z',
        ], $result['payload']);
    }

    public function testMensajeActualizadoTranslatesOldStoredValues(): void
    {
        $esperados = ['sent' => 'enviado', 'delivered' => 'entregado', 'read' => 'leido', 'failed' => 'fallido'];
        foreach ($esperados as $viejo => $nuevo) {
            $result = $this->build('ticket', 100, 'App\Conversacion\MessageUpdatedEvent', ['delivery_status' => $viejo]);
            self::assertSame($nuevo, $result['payload']['estadoDeEntrega'], $viejo);
        }
    }

    public function testDisponibilidadDeAgenteCambiada(): void
    {
        $result = $this->build('agent', 5, TipoDeEventoDeOutbox::DISPONIBILIDAD_DE_AGENTE_CAMBIADA->value, [
            'agent_id' => 5,
            'is_available' => true,
            'override_active' => true,
            'current_load' => 3,
            'max_capacity' => 5,
            'timestamp' => '2026-06-15T10:00:00Z',
        ]);

        self::assertSame('agente.disponibilidad_cambiada', $result['tipoDeEvento']);
        self::assertSame([
            'agenteId' => 5,
            'disponible' => true,
            'disponibilidadManualActiva' => true,
            'cargaActual' => 3,
            'capacidadMaxima' => 5,
            'ocurridoAt' => '2026-06-15T10:00:00Z',
        ], $result['payload']);
    }

    public function testTurnoDeAgenteCambiado(): void
    {
        $result = $this->build('agent', 5, TipoDeEventoDeOutbox::TURNO_DE_AGENTE_CAMBIADO->value, [
            'agent_id' => 5,
            'shift_id' => 10,
            'action' => 'created',
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '16:00',
            'timestamp' => '2026-06-15T08:00:00Z',
        ]);

        self::assertSame('agente.turno_cambiado', $result['tipoDeEvento']);
        self::assertSame([
            'agenteId' => 5,
            'turnoId' => 10,
            'accion' => 'creado',
            'diaDeLaSemana' => 1,
            'horaDeInicio' => '08:00',
            'horaDeFin' => '16:00',
            'ocurridoAt' => '2026-06-15T08:00:00Z',
        ], $result['payload']);

        $borrado = $this->build('agent', 5, 'App\Disponibilidad\AgentShiftChangedEvent', ['action' => 'deleted']);
        self::assertSame('borrado', $borrado['payload']['accion']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function build(string $aggregateType, int $aggregateId, string $tipoDeEvento, array $payload, int $sequence = 1): array
    {
        return $this->factory->build(new EventoOutbox(
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            topic: 'tickets',
            tipoDeEvento: $tipoDeEvento,
            payload: $payload,
            sequence: $sequence,
        ));
    }
}
