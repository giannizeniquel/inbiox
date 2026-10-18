<?php

namespace App\Ticket\Query;

use App\Ticket\Repository\FiltrosDeTicket;

/**
 * Builds the normalized ticket list filters shared by `ListTicketsQuery`,
 * `ExportTicketsCsvQuery`, and the export endpoint's up-front validation.
 * It holds no SQL: `TicketRepository::applyListFilters()` turns the result
 * into WHERE clauses.
 */
class FiltrosDeTicketBuilder
{
    /**
     * @param string $filtro 'all', 'unassigned', 'assigned', or 'mine'
     * @param string|null $agenteId Agent ID for 'mine' filter
     * @param string|null $estado Comma-separated status filter
     * @param int|null $scopeAgenteId Ownership scope: forces WHERE assigned_agent_id = scopeAgentId
     * @param string|null $phoneNumberId Workspace scope: filters by ticket phone_number_id
     * @param string|null $etiqueta Comma-separated tag names (AND semantics, max 5)
     * @param int|null $equipoId Single-select team filter
     *
     * @throws \InvalidArgumentException when more than 5 tags are given
     */
    public function build(
        string $filtro = 'all',
        ?string $agenteId = null,
        ?string $estado = null,
        ?int $scopeAgenteId = null,
        ?string $phoneNumberId = null,
        ?string $etiqueta = null,
        ?int $equipoId = null,
    ): FiltrosDeTicket {
        return new FiltrosDeTicket(
            filtro: $filtro,
            agenteId: $agenteId,
            estado: $estado,
            scopeAgenteId: $scopeAgenteId,
            phoneNumberId: $phoneNumberId,
            etiqueta: $etiqueta,
            equipoId: $equipoId,
        );
    }
}
