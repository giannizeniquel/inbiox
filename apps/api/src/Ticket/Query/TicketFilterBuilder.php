<?php

namespace App\Ticket\Query;

use App\Ticket\Repository\TicketFilters;

/**
 * Builds the normalized ticket list filters shared by `ListTicketsQuery`,
 * `ExportTicketsCsvQuery`, and the export endpoint's up-front validation.
 * It holds no SQL: `TicketRepository::applyListFilters()` turns the result
 * into WHERE clauses.
 */
class TicketFilterBuilder
{
    /**
     * @param string $filter 'all', 'unassigned', 'assigned', or 'mine'
     * @param string|null $agentId Agent ID for 'mine' filter
     * @param string|null $status Comma-separated status filter
     * @param int|null $scopeAgentId Ownership scope: forces WHERE assigned_agent_id = scopeAgentId
     * @param string|null $phoneNumberId Workspace scope: filters by ticket phone_number_id
     * @param string|null $tag Comma-separated tag names (AND semantics, max 5)
     * @param int|null $teamId Single-select team filter
     *
     * @throws \InvalidArgumentException when more than 5 tags are given
     */
    public function build(
        string $filter = 'all',
        ?string $agentId = null,
        ?string $status = null,
        ?int $scopeAgentId = null,
        ?string $phoneNumberId = null,
        ?string $tag = null,
        ?int $teamId = null,
    ): TicketFilters {
        return new TicketFilters(
            filter: $filter,
            agentId: $agentId,
            status: $status,
            scopeAgentId: $scopeAgentId,
            phoneNumberId: $phoneNumberId,
            tag: $tag,
            teamId: $teamId,
        );
    }
}
