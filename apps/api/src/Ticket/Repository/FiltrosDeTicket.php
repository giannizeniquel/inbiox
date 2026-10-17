<?php

namespace App\Ticket\Repository;

use App\Ticket\Etiqueta;

/**
 * The ticket list filters shared by the inbox list and the CSV export,
 * already normalized. `TicketRepository::applyListFilters()` turns them into
 * WHERE clauses; this class holds no SQL.
 *
 * Normalization (unchanged from the old `FiltrosDeTicketBuilder`):
 * - `mine` needs a truthy agent id, otherwise it adds nothing;
 * - unknown statuses are dropped, and an all-unknown list adds nothing;
 * - tags go through `Etiqueta::normalize()`, blanks and duplicates are dropped,
 *   and more than 5 tags throw `\InvalidArgumentException`.
 */
final class FiltrosDeTicket
{
    private const KNOWN_STATUSES = ['new', 'active', 'waiting', 'resolved', 'closed'];
    private const MAX_TAGS = 5;

    /** 'unassigned', 'assigned', or null for no assignment filter. */
    public readonly ?string $assignment;
    public readonly ?string $mineAgentId;
    public readonly ?string $scopeAgentId;
    public readonly ?string $phoneNumberId;
    /** @var list<string> */
    public readonly array $statuses;
    /** @var list<string> */
    public readonly array $tags;
    public readonly ?string $teamId;

    /**
     * @param string $filter 'all', 'unassigned', 'assigned', or 'mine'
     * @param string|null $agentId Agent ID for the 'mine' filter
     * @param string|null $status Comma-separated status filter
     * @param int|null $scopeAgentId Ownership scope: only tickets assigned to this agent
     * @param string|null $phoneNumberId Workspace scope
     * @param string|null $tag Comma-separated tag names (AND semantics, max 5)
     * @param int|null $teamId Single-select team filter
     */
    public function __construct(
        string $filter = 'all',
        ?string $agentId = null,
        ?string $status = null,
        ?int $scopeAgentId = null,
        ?string $phoneNumberId = null,
        ?string $tag = null,
        ?int $teamId = null,
    ) {
        $this->assignment = in_array($filter, ['unassigned', 'assigned'], true) ? $filter : null;
        $this->mineAgentId = ($filter === 'mine' && $agentId) ? $agentId : null;
        $this->scopeAgentId = $scopeAgentId !== null ? (string) $scopeAgentId : null;
        $this->phoneNumberId = $phoneNumberId;

        $statuses = [];
        if ($status) {
            $statuses = array_values(array_filter(
                array_map('trim', explode(',', $status)),
                fn ($s) => in_array($s, self::KNOWN_STATUSES),
            ));
        }
        $this->statuses = $statuses;

        $tags = [];
        if (null !== $tag && '' !== trim($tag)) {
            $tags = array_values(array_unique(array_filter(
                array_map(fn ($n) => Etiqueta::normalizar($n), explode(',', $tag)),
                fn ($n) => '' !== $n,
            )));
            if (count($tags) > self::MAX_TAGS) {
                throw new \InvalidArgumentException('A maximum of 5 tags may be filtered at once');
            }
        }
        $this->tags = $tags;

        $this->teamId = $teamId !== null ? (string) $teamId : null;
    }
}
