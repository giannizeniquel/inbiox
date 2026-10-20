<?php

namespace App\Ticket\Query;

use App\Ticket\Repository\FiltrosDeTicket;

/**
 * Arma los filtros normalizados de la lista de tickets compartidos por `ListTicketsQuery`,
 * `ExportarTicketsCsvQuery` y la validación previa del endpoint de exportación.
 * No contiene SQL: `TicketRepository::aplicarFiltrosDeLista()` convierte el resultado
 * en cláusulas WHERE.
 */
class FiltrosDeTicketBuilder
{
    /**
     * @param string $filtro 'all', 'unassigned', 'assigned' o 'mine'
     * @param string|null $agenteId ID del agente para el filtro 'mine'
     * @param string|null $estado Filtro de estados separados por comas
     * @param int|null $scopeAgenteId Alcance de responsabilidad: fuerza WHERE assigned_agent_id = scopeAgentId
     * @param string|null $phoneNumberId Alcance del espacio de trabajo: filtra por el phone_number_id del ticket
     * @param string|null $etiqueta Nombres de etiquetas separados por comas (semántica AND, máximo 5)
     * @param int|null $equipoId Filtro de equipo de selección única
     *
     * @throws \InvalidArgumentException cuando se indican más de 5 etiquetas
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
