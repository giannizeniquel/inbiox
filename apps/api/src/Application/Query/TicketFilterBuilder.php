<?php

namespace App\Application\Query;

use App\Domain\Tag\Tag;

/**
 * Pure WHERE/params builder shared by `ListTicketsQuery` and
 * `ExportTicketsCsvQuery`. Extracted verbatim from `ListTicketsQuery`'s
 * original filter block — clause and param ORDER is behavior-preserving:
 * filter/mine → ownership scope → workspace scope → status whitelist →
 * tag AND-semantics (max 5, `Tag::normalize()`) → team (single-select).
 *
 * Callers appending their own clauses (date range, keyset cursor, …) MUST
 * do so AFTER calling `build()` so existing positional `?` params stay
 * aligned.
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
     * @param int|null $teamId Single-select team filter, appended LAST — see
     *                         design.md D6, all 3 callers use named arguments
     *
     * @return array{0: list<string>, 1: list<string>} [$where, $params]
     */
    public function build(
        string $filter = 'all',
        ?string $agentId = null,
        ?string $status = null,
        ?int $scopeAgentId = null,
        ?string $phoneNumberId = null,
        ?string $tag = null,
        ?int $teamId = null,
    ): array {
        $where = [];
        $params = [];

        if ($filter === 'unassigned') {
            $where[] = 't.assigned_agent_id IS NULL';
        } elseif ($filter === 'assigned') {
            $where[] = 't.assigned_agent_id IS NOT NULL';
        } elseif ($filter === 'mine' && $agentId) {
            $where[] = 't.assigned_agent_id = ?';
            $params[] = $agentId;
        }

        // Ownership scope: force filter to own tickets for AGENT role
        // This is applied BEFORE the 'mine' filter check to prevent AGENT from
        // bypassing the scope via the 'all' filter.
        if (null !== $scopeAgentId) {
            $where[] = 't.assigned_agent_id = ?';
            $params[] = (string) $scopeAgentId;
        }

        // Workspace scope: filter by phone_number_id for AGENT with active workspace
        if (null !== $phoneNumberId) {
            $where[] = 't.phone_number_id = ?';
            $params[] = $phoneNumberId;
        }

        if ($status) {
            $statuses = array_filter(array_map('trim', explode(',', $status)), fn($s) => in_array($s, ['new','active','waiting','resolved','closed']));
            if ($statuses) {
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $where[] = "t.status IN ($placeholders)";
                $params = array_merge($params, array_values($statuses));
            }
        }

        if (null !== $tag && '' !== trim($tag)) {
            $tags = array_values(array_unique(array_filter(
                array_map(fn($n) => Tag::normalize($n), explode(',', $tag)),
                fn($n) => '' !== $n,
            )));
            if (count($tags) > 5) {
                throw new \InvalidArgumentException('A maximum of 5 tags may be filtered at once');
            }
            foreach ($tags as $name) {
                $where[] = 'EXISTS (SELECT 1 FROM ticket_tags tt JOIN tags tg ON tg.id = tt.tag_id
                                    WHERE tt.ticket_id = t.id AND tg.name = ?)';
                $params[] = $name;
            }
        }

        if (null !== $teamId) {
            $where[] = 't.team_id = ?';
            $params[] = (string) $teamId;
        }

        return [$where, $params];
    }
}
