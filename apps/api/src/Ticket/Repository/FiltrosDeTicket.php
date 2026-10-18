<?php

namespace App\Ticket\Repository;

use App\Ticket\Etiqueta;

/**
 * The ticket list filters shared by the inbox list and the CSV export,
 * already normalized. `TicketRepository::aplicarFiltrosDeLista()` turns them into
 * WHERE clauses; this class holds no SQL.
 *
 * Normalization (unchanged from the old `FiltrosDeTicketBuilder`):
 * - `mine` needs a truthy agent id, otherwise it adds nothing;
 * - unknown statuses are dropped, and an all-unknown list adds nothing;
 * - tags go through `Etiqueta::normalizar()`, blanks and duplicates are dropped,
 *   and more than 5 tags throw `\InvalidArgumentException`.
 */
final class FiltrosDeTicket
{
    private const KNOWN_STATUSES = ['new', 'active', 'waiting', 'resolved', 'closed'];
    private const MAX_TAGS = 5;

    /** 'unassigned', 'assigned', or null for no assignment filter. */
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
     * @param string $filtro 'all', 'unassigned', 'assigned', or 'mine'
     * @param string|null $agenteId Agent ID for the 'mine' filter
     * @param string|null $estado Comma-separated status filter
     * @param int|null $scopeAgenteId Ownership scope: only tickets assigned to this agent
     * @param string|null $phoneNumberId Workspace scope
     * @param string|null $etiqueta Comma-separated tag names (AND semantics, max 5)
     * @param int|null $equipoId Single-select team filter
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
                fn ($s) => in_array($s, self::KNOWN_STATUSES),
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
