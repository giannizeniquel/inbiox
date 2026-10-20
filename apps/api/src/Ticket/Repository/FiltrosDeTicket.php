<?php

namespace App\Ticket\Repository;

use App\Ticket\EstadoDeTicket;
use App\Ticket\Etiqueta;

/**
 * Los filtros de la lista de tickets compartidos por la lista de la bandeja y la exportación CSV,
 * ya normalizados. `TicketRepository::aplicarFiltrosDeLista()` los convierte en
 * cláusulas WHERE; esta clase no contiene SQL.
 *
 * Normalización (sin cambios respecto del antiguo `FiltrosDeTicketBuilder`):
 * - `mine` necesita un id de agente con valor verdadero; si no, no agrega nada;
 * - los estados desconocidos se descartan, y una lista con todos desconocidos no agrega nada;
 * - las etiquetas pasan por `Etiqueta::normalizar()`, se descartan las vacías y las duplicadas,
 *   y más de 5 etiquetas lanzan `\InvalidArgumentException`.
 */
final class FiltrosDeTicket
{
    private const MAX_TAGS = 5;

    /** 'unassigned', 'assigned' o null si no hay filtro de asignación. */
    public readonly ?string $asignacion;
    public readonly ?string $misAgenteId;
    public readonly ?string $scopeAgenteId;
    public readonly ?string $phoneNumberId;
    /** @var list<string> */
    public readonly array $estados;
    /** @var list<string> */
    public readonly array $etiquetas;
    public readonly ?string $equipoId;

    /**
     * @param string $filtro 'all', 'unassigned', 'assigned' o 'mine'
     * @param string|null $agenteId ID del agente para el filtro 'mine'
     * @param string|null $estado Filtro de estados separados por comas
     * @param int|null $scopeAgenteId Alcance de responsabilidad: solo los tickets asignados a este agente
     * @param string|null $phoneNumberId Alcance del espacio de trabajo
     * @param string|null $etiqueta Nombres de etiquetas separados por comas (semántica AND, máximo 5)
     * @param int|null $equipoId Filtro de equipo de selección única
     */
    public function __construct(
        string $filtro = 'all',
        ?string $agenteId = null,
        ?string $estado = null,
        ?int $scopeAgenteId = null,
        ?string $phoneNumberId = null,
        ?string $etiqueta = null,
        ?int $equipoId = null,
    ) {
        $this->asignacion = in_array($filtro, ['unassigned', 'assigned'], true) ? $filtro : null;
        $this->misAgenteId = ($filtro === 'mine' && $agenteId) ? $agenteId : null;
        $this->scopeAgenteId = $scopeAgenteId !== null ? (string) $scopeAgenteId : null;
        $this->phoneNumberId = $phoneNumberId;

        $estados = [];
        if ($estado) {
            $estados = array_values(array_filter(
                array_map('trim', explode(',', $estado)),
                fn ($s) => EstadoDeTicket::tryFrom($s) !== null,
            ));
        }
        $this->estados = $estados;

        $etiquetas = [];
        if (null !== $etiqueta && '' !== trim($etiqueta)) {
            $etiquetas = array_values(array_unique(array_filter(
                array_map(fn ($n) => Etiqueta::normalizar($n), explode(',', $etiqueta)),
                fn ($n) => '' !== $n,
            )));
            if (count($etiquetas) > self::MAX_TAGS) {
                throw new \InvalidArgumentException('A maximum of 5 tags may be filtered at once');
            }
        }
        $this->etiquetas = $etiquetas;

        $this->equipoId = $equipoId !== null ? (string) $equipoId : null;
    }
}
