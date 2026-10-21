<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Disponibilidad\Query\DisponibilidadDeAgenteDTO;
use PHPUnit\Framework\TestCase;

/**
 * Pins the JSON keys and key order of the agent availability DTO. The keys are
 * part of the API contract and stay in English while the property names are Spanish.
 */
final class DisponibilidadDTOJsonKeysCharacterizationTest extends TestCase
{
    public function testAgentAvailabilityKeepsItsJsonKeys(): void
    {
        $dto = new DisponibilidadDeAgenteDTO(
            id: 5,
            nombre: 'Agent',
            isActivoAhora: true,
            cantidadDeTicketsActivos: 2,
            maxTicketsActivos: 4,
        );

        self::assertSame([
            'id' => 5,
            'nombre' => 'Agent',
            'activoAhora' => true,
            'cantidadDeTicketsActivos' => 2,
            'maximoDeTicketsActivos' => 4,
        ], $dto->toArray());
    }
}
