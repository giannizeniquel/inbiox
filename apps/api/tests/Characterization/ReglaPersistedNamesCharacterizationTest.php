<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Automatizacion\ContextoDeRegla;
use App\Automatizacion\ReglaDeEtiquetadoPorPalabraClave;
use App\Automatizacion\ReglaDeReaperturaAutomatica;
use App\Automatizacion\ReglaInterface;
use App\Conversacion\Mensaje;
use App\Ticket\Ticket;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rule names and execution keys stored in rule_execution_logs
 * (rule_name, execution_key). Changing them would orphan existing rows and
 * let a rule run again for a message it already handled.
 */
final class ReglaPersistedNamesCharacterizationTest extends TestCase
{
    public function testRulesKeepTheirStoredNames(): void
    {
        self::assertSame('auto_reopen', $this->regla(ReglaDeReaperturaAutomatica::class)->getNombre());
        self::assertSame('keyword_tagging', $this->regla(ReglaDeEtiquetadoPorPalabraClave::class)->getNombre());
    }

    public function testAutoReopenExecutionKeyFormat(): void
    {
        $regla = $this->regla(ReglaDeReaperturaAutomatica::class);

        self::assertSame('auto_reopen:7:9', $regla->armarClaveDeEjecucion($this->contexto(7, 9)));
        self::assertSame('auto_reopen:7:no_message', $regla->armarClaveDeEjecucion($this->contexto(7, null)));
    }

    public function testKeywordTaggingExecutionKeyFormat(): void
    {
        $regla = $this->regla(ReglaDeEtiquetadoPorPalabraClave::class);

        self::assertSame('tag_error:7:9', $regla->armarClaveDeEjecucion($this->contexto(7, 9)));
        self::assertSame('tag_error:7:no_message', $regla->armarClaveDeEjecucion($this->contexto(7, null)));
    }

    /**
     * Name and key do not use the rules' dependencies, so the constructor is skipped.
     *
     * @param class-string<ReglaInterface> $class
     */
    private function regla(string $class): ReglaInterface
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function contexto(int $ticketId, ?int $mensajeId): ContextoDeRegla
    {
        $ticket = new Ticket();
        $this->setId($ticket, $ticketId);

        $mensaje = null;
        if ($mensajeId !== null) {
            $mensaje = new Mensaje();
            $this->setId($mensaje, $mensajeId);
        }

        return new ContextoDeRegla($ticket, $mensaje);
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
