<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Ticket\Query\DetalleDeTicketDTO;
use App\Ticket\Query\TicketDeListaDTO;
use PHPUnit\Framework\TestCase;

/**
 * Pins the JSON keys and key order of the ticket DTOs. The keys are part of
 * the API contract and stay in English while the property names are Spanish.
 */
final class TicketDTOJsonKeysCharacterizationTest extends TestCase
{
    public function testTicketDetailKeepsItsJsonKeys(): void
    {
        $dto = new DetalleDeTicketDTO(
            ticketId: 7,
            estado: 'active',
            prioridad: 'medium',
            contacto: ['id' => 3, 'phone' => '+5491100000000', 'name' => 'Ana'],
            responsable: ['id' => 5, 'name' => 'Agent'],
            marcasDeTiempo: ['created_at' => '2026-10-01T10:00:00Z'],
            metricas: ['unread_count' => 2],
            ultimoMensaje: 'hola',
        );

        self::assertSame([
            'ticket_id' => 7,
            'status' => 'active',
            'priority' => 'medium',
            'contact' => ['id' => 3, 'phone' => '+5491100000000', 'name' => 'Ana'],
            'owner' => ['id' => 5, 'name' => 'Agent'],
            'timestamps' => ['created_at' => '2026-10-01T10:00:00Z'],
            'metrics' => ['unread_count' => 2],
            'last_message' => 'hola',
        ], $dto->toArray());
    }

    public function testTicketListItemKeepsItsJsonKeys(): void
    {
        $dto = new TicketDeListaDTO(
            ticketId: 7,
            estado: 'waiting',
            prioridad: 'medium',
            contacto: ['id' => 3, 'phone' => '+5491100000000', 'name' => null],
            ultimoMensaje: null,
            responsable: null,
            metricas: ['unread_count' => 0],
            marcasDeTiempo: ['updated_at' => '2026-10-01T10:00:00Z'],
        );

        self::assertSame([
            'ticket_id' => 7,
            'status' => 'waiting',
            'priority' => 'medium',
            'contact' => ['id' => 3, 'phone' => '+5491100000000', 'name' => null],
            'last_message' => null,
            'owner' => null,
            'metrics' => ['unread_count' => 0],
            'timestamps' => ['updated_at' => '2026-10-01T10:00:00Z'],
        ], $dto->toArray());
    }
}
