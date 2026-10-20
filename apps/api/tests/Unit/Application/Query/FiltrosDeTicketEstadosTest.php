<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Query;

use App\Ticket\Repository\FiltrosDeTicket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fija qué valores del filtro `status` acepta el listado de tickets: los cinco
 * estados exactos, sin distinguir espacios alrededor, y nada más.
 */
final class FiltrosDeTicketEstadosTest extends TestCase
{
    /**
     * @param list<string> $esperados
     */
    #[DataProvider('entradas')]
    public function testAceptaSoloLosEstadosConocidos(?string $estado, array $esperados): void
    {
        self::assertSame($esperados, (new FiltrosDeTicket(estado: $estado))->estados);
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function entradas(): iterable
    {
        yield 'null' => [null, []];
        yield 'vacio' => ['', []];
        yield 'uno' => ['nuevo', ['nuevo']];
        yield 'todos' => ['nuevo,activo,en_espera,resuelto,cerrado', ['nuevo', 'activo', 'en_espera', 'resuelto', 'cerrado']];
        yield 'mayusculas' => ['NEW', []];
        yield 'capitalizado' => ['Active', []];
        yield 'con espacios' => [' resuelto , cerrado ', ['resuelto', 'cerrado']];
        yield 'mezcla con desconocido' => ['nuevo,bogus', ['nuevo']];
        yield 'cero' => ['0', []];
        yield 'solo coma' => [',', []];
    }
}
