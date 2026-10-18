<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Contacto\Query\ContactoDeListaDTO;
use App\Contacto\Query\DetalleDeContactoDTO;
use App\Contacto\Query\EventoDeLineaDeTiempoDTO;
use App\Contacto\UseCase\ResultadoDeImportacionDeContactosDTO;
use PHPUnit\Framework\TestCase;

/**
 * Pins the JSON keys and key order of the contact DTOs. The keys are part of
 * the API contract and stay in English while the property names are Spanish.
 */
final class ContactoDTOJsonKeysCharacterizationTest extends TestCase
{
    public function testContactDetailKeepsItsJsonKeys(): void
    {
        $dto = new DetalleDeContactoDTO(
            id: 3,
            telefono: '+5491100000000',
            nombre: 'Ana',
            estado: 'active',
            canal: 'whatsapp',
            responsableId: 5,
            email: 'ana@example.com',
            marcasDeTiempo: ['created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-02 10:00:00'],
        );

        self::assertSame([
            'id' => 3,
            'phone' => '+5491100000000',
            'name' => 'Ana',
            'status' => 'active',
            'channel' => 'whatsapp',
            'owner_id' => 5,
            'email' => 'ana@example.com',
            'timestamps' => ['created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-02 10:00:00'],
        ], $dto->toArray());
    }

    public function testContactListItemKeepsItsJsonKeys(): void
    {
        $dto = new ContactoDeListaDTO(
            id: 3,
            telefono: '+5491100000000',
            nombre: null,
            estado: null,
            canal: null,
            responsableId: null,
            email: null,
            marcasDeTiempo: ['created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-02 10:00:00'],
        );
        $dto->ticketActivoId = 9;

        self::assertSame([
            'id' => 3,
            'phone' => '+5491100000000',
            'name' => null,
            'status' => null,
            'channel' => null,
            'owner_id' => null,
            'email' => null,
            'timestamps' => ['created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-02 10:00:00'],
            'activeTicketId' => 9,
        ], $dto->toArray());
    }

    public function testTimelineEventKeepsItsJsonKeys(): void
    {
        $dto = new EventoDeLineaDeTiempoDTO(
            tipo: 'ticket.assigned',
            ocurridoAt: '2026-10-01T10:00:00+00:00',
            contenido: null,
            ticketId: 7,
            estadoDeTicket: 'active',
            asignadoA: 'Agent',
            agenteId: 5,
            direccion: null,
        );

        self::assertSame([
            'type' => 'ticket.assigned',
            'occurredAt' => '2026-10-01T10:00:00+00:00',
            'content' => null,
            'ticketId' => 7,
            'ticketStatus' => 'active',
            'assignedTo' => 'Agent',
            'agentId' => 5,
            'direction' => null,
        ], $dto->toArray());
    }

    public function testImportResultKeepsItsJsonKeys(): void
    {
        $error = ['row' => 2, 'phone' => '123', 'reason' => 'invalid_phone', 'message' => 'Invalid phone number'];
        $dto = new ResultadoDeImportacionDeContactosDTO(
            totalDeFilas: 3,
            importados: 2,
            fallidos: 1,
            errores: [$error],
            erroresTruncados: false,
        );

        self::assertSame([
            'totalRows' => 3,
            'imported' => 2,
            'failed' => 1,
            'errors' => [$error],
            'errorsTruncated' => false,
        ], $dto->toArray());
    }
}
