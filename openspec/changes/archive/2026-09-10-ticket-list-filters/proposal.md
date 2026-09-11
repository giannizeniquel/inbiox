# Proposal: Ticket List Filters — tag filter + `waiting` status fix

## Intent

Supervisors cannot narrow the inbox by tag, and cannot filter by the `waiting` status at all. Tags are already created and attached (`TagController`, `ticket_tags`), but no read path exposes them — the data exists and is simply unreachable. Separately, `ListTicketsQuery.php:79` whitelists `['new','active','resolved','closed']`, silently dropping `waiting` even though it is a first-class `TicketStatus` and an SLA-active state in `TicketStateMachine`. Filtering by "en espera" returns wrong results today with no error. Tier 1 item #1 of `analisis-brecha-requerimientos.md` (gaps #8a and #18).

## Scope

### In Scope
- Add `waiting` to the status whitelist (`ListTicketsQuery.php:79`).
- Add optional `tag` parameter to `ListTicketsQuery::execute()` — comma-separated tag names, **AND semantics** (ticket must carry ALL requested tags).
- Wire `?tag=` through `InboxController::listTickets()` (GET `/tickets`).
- Integration tests for status whitelist, single-tag, multi-tag AND, unknown tag, and pagination/`total` correctness.

### Out of Scope
- Frontend. The Angular inbox fetches unfiltered and filters client-side; no dead UI exists to reconnect.
- New entities, schema changes, migrations — `Tag`/`TicketTag` already exist.
- Tag filtering in reporting (`ReportingRepository`, gap #17), tag negation/exclusion, OR semantics, team/company/query-type filters.
- Fixing the `TicketTag` `inversedBy: 'ticketTags'` mapping inconsistency (harmless today).

## Capabilities

### New Capabilities
- `ticket-list-filters`: filtering and pagination contract for the inbox ticket list (GET `/tickets`) — status, assignment, scope, and tag filters.

### Modified Capabilities
- None.

## Approach

**N-stacked correlated `EXISTS`, one per requested tag**, appended to the existing `$where[]`/`$params[]` array:

```sql
EXISTS (SELECT 1 FROM ticket_tags tt JOIN tags tg ON tt.tag_id = tg.id
        WHERE tt.ticket_id = t.id AND tg.name = ?)
```

Chosen over `GROUP BY t.id HAVING COUNT(DISTINCT tg.id) = :n` because:

1. **`$countSql` stays correct for free.** It is `SELECT COUNT(*) FROM tickets t $whereClause` — no joins. Correlated `EXISTS` references only `t`, so the same `$whereClause` remains valid in both queries. `GROUP BY`/`HAVING` would force the count into a subquery wrapper and restructure both statements.
2. **AND semantics is emergent**, not engineered — `$where` is already joined with `' AND '`.
3. **No row duplication**, so `LIMIT`/`OFFSET` and `total` stay sound (the trap a naive `JOIN` falls into).
4. Composes additively with ownership/workspace scope: the filter can only narrow, never widen, an agent's visible set.

Tag names are normalized via `Tag::normalize()` (lowercase/trim) before binding. Tag count is capped (proposed: 5) to bound subquery fan-out.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Application/Query/ListTicketsQuery.php` | Modified | `waiting` in whitelist; new `?string $tag` param; EXISTS clauses |
| `apps/api/src/Controller/InboxController.php` | Modified | Read `tag` query param, forward to query |
| `apps/api/tests/Integration/` | New | Filter behavior coverage (none exists today) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Zero existing coverage of `ListTicketsQuery` filters — no regression net | High | Write tests first (TDD); assert `total` and item count together |
| Unknown/misspelled tag returns empty list, may read as a bug to users | Med | Correct AND behavior; document in spec; frontend surfaces empty state later |
| Unbounded tag count degrades query plan | Low | Cap at 5 tags; extra values rejected or truncated (spec decides) |
| FE `TicketFilter.waiting` means "unassigned", colliding with `TicketStatus::WAITING` | Med | Backend-only change; flagged for the future FE task, not resolved here |
| `total` drifts from page contents if EXISTS lands in `$sql` but not `$countSql` | Low | Single shared `$whereClause` — do not diverge the two builders |

## Rollback Plan

Revert the two source files. The change is additive and stateless: no migration, no schema change, no data written. Omitting `?tag=` reproduces exact current behavior, so partial rollback is also safe — reverting `InboxController` alone disables the feature while leaving the query harmless.

## Dependencies

- None. `Tag`, `TicketTag`, `tags`, `ticket_tags` already exist and are populated by `AddTagToTicketUseCase`.

## Success Criteria

- [ ] `GET /tickets?status=waiting` returns waiting tickets (currently returns unfiltered results).
- [ ] `GET /tickets?tag=vip` returns only tickets carrying `vip`.
- [ ] `GET /tickets?tag=vip,urgente` returns only tickets carrying **both**; a ticket with one of them is excluded.
- [ ] `meta.total` matches the true filtered count under multi-tag filters — no duplicate-row inflation.
- [ ] Tag filter narrows within, and never escapes, ownership and workspace scope.
- [ ] Requests without `tag` behave byte-identically to today.
