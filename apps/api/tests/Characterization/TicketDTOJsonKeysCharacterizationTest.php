<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Ticket\Query\DetalleDeTicketDTO;
use App\Ticket\Query\TicketDeListaDTO;
use PHPUnit\Framework\TestCase;

/**
 * Pins the JSON keys and key order of the ticket DTOs (contract annex A.11).
 */
final class TicketDTOJsonKeysCharacterizationTest extends TestCase
{
    public function testTicketDetailUsesTheContractKeys(): void
    {
        $dto = new DetalleDeTicketDTO(
            ticketId: 7,
            estado: 'activo',
            prioridad: 'media',
            contacto: ['id' => 3, 'telefono' => '+5491100000000', 'nombre' => 'Ana'],
            responsable: ['id' => 5, 'nombre' => 'Agent'],
            marcasDeTiempo: ['abiertoAt' => '2026-10-01T10:00:00Z'],
            metricas: ['cantidadDeNoLeidos' => 2],
            ultimoMensaje: 'hola',
        );

        self::assertSame([
            'ticketId' => 7,
            'estado' => 'activo',
            'prioridad' => 'media',
            'contacto' => ['id' => 3, 'telefono' => '+5491100000000', 'nombre' => 'Ana'],
            'responsable' => ['id' => 5, 'nombre' => 'Agent'],
            'fechas' => ['abiertoAt' => '2026-10-01T10:00:00Z'],
            'metricas' => ['cantidadDeNoLeidos' => 2],
            'ultimoMensaje' => 'hola',
        ], $dto->toArray());
    }

    public function testTicketDetailDefaultsItsUnreadCountToZero(): void
    {
        $dto = new DetalleDeTicketDTO(
            ticketId: 7,
            estado: 'nuevo',
            prioridad: 'media',
            contacto: [],
            responsable: null,
            marcasDeTiempo: [],
        );

        self::assertSame(['cantidadDeNoLeidos' => 0], $dto->toArray()['metricas']);
    }

    public function testTicketListItemUsesTheContractKeys(): void
    {
        $dto = new TicketDeListaDTO(
            ticketId: 7,
            estado: 'en_espera',
            prioridad: 'media',
            contacto: ['id' => 3, 'telefono' => '+5491100000000', 'nombre' => null],
            ultimoMensaje: null,
            responsable: null,
            metricas: ['cantidadDeNoLeidos' => 0],
            marcasDeTiempo: ['actualizadoAt' => '2026-10-01T10:00:00Z'],
        );

        self::assertSame([
            'ticketId' => 7,
            'estado' => 'en_espera',
            'prioridad' => 'media',
            'contacto' => ['id' => 3, 'telefono' => '+5491100000000', 'nombre' => null],
            'ultimoMensaje' => null,
            'responsable' => null,
            'metricas' => ['cantidadDeNoLeidos' => 0],
            'fechas' => ['actualizadoAt' => '2026-10-01T10:00:00Z'],
        ], $dto->toArray());
    }
}
