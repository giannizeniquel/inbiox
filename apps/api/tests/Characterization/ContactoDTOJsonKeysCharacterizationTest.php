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
 * the API contract (docs/contrato-api.md, annex A.7).
 */
final class ContactoDTOJsonKeysCharacterizationTest extends TestCase
{
    public function testContactDetailKeepsItsJsonKeys(): void
    {
        $dto = new DetalleDeContactoDTO(
            id: 3,
            telefono: '+5491100000000',
            nombre: 'Ana',
            estado: 'activo',
            canal: 'whatsapp',
            responsableId: 5,
            email: 'ana@example.com',
            marcasDeTiempo: ['creadoAt' => '2026-10-01 10:00:00', 'actualizadoAt' => '2026-10-02 10:00:00'],
        );

        self::assertSame([
            'id' => 3,
            'telefono' => '+5491100000000',
            'nombre' => 'Ana',
            'estado' => 'activo',
            'canal' => 'whatsapp',
            'responsableId' => 5,
            'email' => 'ana@example.com',
            'fechas' => ['creadoAt' => '2026-10-01 10:00:00', 'actualizadoAt' => '2026-10-02 10:00:00'],
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
            marcasDeTiempo: ['creadoAt' => '2026-10-01 10:00:00', 'actualizadoAt' => '2026-10-02 10:00:00'],
        );
        $dto->ticketActivoId = 9;

        self::assertSame([
            'id' => 3,
            'telefono' => '+5491100000000',
            'nombre' => null,
            'estado' => null,
            'canal' => null,
            'responsableId' => null,
            'email' => null,
            'fechas' => ['creadoAt' => '2026-10-01 10:00:00', 'actualizadoAt' => '2026-10-02 10:00:00'],
            'ticketActivoId' => 9,
        ], $dto->toArray());
    }

    public function testTimelineEventKeepsItsJsonKeys(): void
    {
        $dto = new EventoDeLineaDeTiempoDTO(
            tipo: 'ticket.asignado',
            ocurridoAt: '2026-10-01T10:00:00+00:00',
            contenido: null,
            ticketId: 7,
            estadoDeTicket: 'activo',
            asignadoA: 'Agent',
            agenteId: 5,
            direccion: null,
        );

        self::assertSame([
            'tipo' => 'ticket.asignado',
            'ocurridoAt' => '2026-10-01T10:00:00+00:00',
            'contenido' => null,
            'ticketId' => 7,
            'estadoDeTicket' => 'activo',
            'asignadoA' => 'Agent',
            'agenteId' => 5,
            'direccion' => null,
        ], $dto->toArray());
    }

    public function testImportResultKeepsItsJsonKeys(): void
    {
        $error = ['fila' => 2, 'telefono' => '123', 'motivo' => 'invalid_phone', 'mensaje' => 'Invalid phone number'];
        $dto = new ResultadoDeImportacionDeContactosDTO(
            totalDeFilas: 3,
            importados: 2,
            fallidos: 1,
            errores: [$error],
            erroresTruncados: false,
        );

        self::assertSame([
            'totalDeFilas' => 3,
            'importados' => 2,
            'fallidos' => 1,
            'errores' => [$error],
            'erroresTruncados' => false,
        ], $dto->toArray());
    }
}
