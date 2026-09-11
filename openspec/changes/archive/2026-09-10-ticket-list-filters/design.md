# Design: Ticket List Filters — tag filter + `waiting` status fix

## Technical Approach

Two additive edits to `ListTicketsQuery::execute()` plus one param read in `InboxController::listTickets()`. No entities, schema, or migrations: `Tag`, `TicketTag`, `tags`, `ticket_tags` already exist and are populated by `AddTagToTicketUseCase`.

1. **Whitelist fix** (`ListTicketsQuery.php:79`): add `'waiting'` to the `in_array` allow-list, matching the 5 `TicketStatus` cases.
2. **Tag filter**: new `?string $tag` param, parsed into ≤5 normalized names, each emitting one correlated `EXISTS` clause appended to the existing `$where[]`/`$params[]` arrays.

### Correction to the incoming brief

The query binds **positional `?` placeholders**, not named `:status`/`:agentId` params (verified at `ListTicketsQuery.php:60-84`). Parameter *naming collision is therefore a non-issue*; the real hazard is **positional ordering** — `$params` is a flat list consumed left-to-right against `$whereClause`. The tag block MUST be appended after the status block so pushed params stay aligned with emitted placeholders.

## Architecture Decisions

| Decision | Choice | Alternatives rejected | Rationale |
|---|---|---|---|
| Multi-tag SQL shape | N-stacked correlated `EXISTS`, one per tag, referencing only alias `t` | `GROUP BY t.id HAVING COUNT(DISTINCT tg.id) = n`; naive `JOIN ... IN (...)` | `$countSql` is `SELECT COUNT(*) FROM tickets t $whereClause` and **lacks the joins `$sql` has**. Only `t`-scoped predicates stay valid in both. `GROUP BY` would force a subquery wrapper on both builders; a `JOIN` duplicates rows and corrupts `LIMIT`/`OFFSET` and `total`. |
| AND semantics | Emergent — `$where` is already `implode(' AND ', …)` | Explicit intersection logic | Zero engineering: each `EXISTS` is an independent boolean predicate. |
| Placeholders | Positional `?`, params appended in clause order | Converting the method to named params | Follows the existing pattern; a mixed-style query is a Doctrine DBAL error. |
| Tag cap (5) exceeded | **Throw `\InvalidArgumentException` → HTTP 400** | Silent truncation to first 5 | Truncation would silently under-filter, leaving callers unable to explain why filtering "didn't fully apply". `InboxController.php:83-84` already catches `\InvalidArgumentException` and returns `{"error": …}` with 400 — no new error plumbing. Matches the backend skill rule "all input validation in UseCases, not controllers". |
| Unknown tag name | Empty result set, no error | Pre-validating against `tags` | Consistent with AND semantics; avoids an extra round-trip and leaks no tag inventory. |
| Normalization | `Tag::normalize()` on each segment | Raw comparison | `tags.name` is stored normalized (lowercase/trim/collapsed spaces); normalizing input makes matching exact. |

## Data Flow

    GET /tickets?tag=vip,,urgente&status=waiting
         │
         ▼
    InboxController::listTickets()
         │  $request->query->get('tag')  ──→ forwarded verbatim
         ▼
    ListTicketsQuery::execute(tag: ...)
         │  explode(',') → Tag::normalize() → drop blanks → dedupe → cap(5)
         │       │
         │       └── >5 ⇒ throw InvalidArgumentException ⇒ 400
         ▼
    $where[] += one EXISTS per tag     $params[] += one name per tag
         │
         └──→ $whereClause ──┬──→ $sql      (joins + LIMIT/OFFSET)
                             └──→ $countSql (no joins — EXISTS is t-scoped, still valid)

## Interfaces / Contracts

Signature gains one trailing optional param (append-only; all callers keep working):

```php
public function execute(
    …,
    ?string $phoneNumberId = null,
    ?string $tag = null,   // comma-separated tag names, AND semantics, max 5
): array
```

Parse + emit, appended immediately after the existing `if ($status) { … }` block:

```php
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
```

Blanks are dropped **before** the cap, so `?tag=vip,,urgente` counts as 2. Dedupe also precedes the cap, so `?tag=vip,vip` counts as 1. Empty `?tag=` is treated as absent.

Controller: read `$tag = $request->query->get('tag');` beside `$status` and pass `tag: $tag` in the named-argument call.

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/api/src/Application/Query/ListTicketsQuery.php` | Modify | `waiting` in whitelist (L79); `?string $tag` param; parse/cap/EXISTS block; `use App\Domain\Tag\Tag;` |
| `apps/api/src/Controller/InboxController.php` | Modify | Read `tag` query param, forward it |
| `apps/api/tests/Integration/ListTicketsQueryFilterTest.php` | Create | Filter behavior coverage (none exists today) |

## Testing Strategy

Coverage is greenfield — no existing test asserts this query's filter behavior. Model the new test on `TicketQueriesLastInboundMessageAtTest`: `KernelTestCase`, real DB, `setUp()` truncating `ticket_tags`, `tickets`, `conversations`, `contacts` in FK-safe order, resolving the query from the container.

| Layer | What to test | Approach |
|---|---|---|
| Integration | `status=waiting` returns waiting tickets (RED before the L79 fix) | Seed one ticket per status; assert only the waiting one returns |
| Integration | Single tag returns only tagged tickets | Seed tagged + untagged; assert ids |
| Integration | **Multi-tag AND**: ticket with only 1 of 2 requested tags is EXCLUDED | Seed A={vip,urgente}, B={vip}; `tag=vip,urgente` ⇒ only A. The core anti-regression test |
| Integration | Pagination + `total` correctness under multi-tag | Seed >`limit` matching tickets; assert `count(items) === limit` **and** `total` equals true match count — catches row-duplication inflation |
| Integration | Unknown tag ⇒ empty items, `total === 0`, no exception | `tag=noexiste` |
| Integration | Blank segments discarded | `tag=vip,,urgente` behaves identically to `tag=vip,urgente` |
| Integration | Empty/absent `tag` ⇒ no filter | `tag=''` and `tag=null` return the same set |
| Integration | Cap enforced | 6 tags ⇒ `expectException(\InvalidArgumentException::class)`; 5 ⇒ no throw |
| Integration | Tag filter composes with scope | Non-null `scopeAgentId` + tag ⇒ intersection only; filter never widens visibility |
| Functional (optional) | Controller maps cap breach to HTTP 400 | `WebTestCase` on `GET /tickets?tag=` with 6 values |

Assert `total` and `count(items)` **together** in every tag test — that pairing is what detects `$sql`/`$countSql` divergence.

## Threat Matrix

N/A — no routing table change, shell command, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. One new query param on an existing route. SQL injection is structurally excluded: tag names are bound as positional parameters, never interpolated (unlike `$limit`/`$offset`, which are pre-existing int-cast interpolations left untouched).

## Migration / Rollout

No migration required. Additive and stateless: no schema change, no data written. Omitting `?tag=` reproduces current behavior exactly. Reverting `InboxController` alone disables the feature while leaving the query harmless.

## Open Questions

- [ ] None blocking. The four product questions from the proposal are resolved above (unknown tag ⇒ empty; cap 5 ⇒ 400; blanks ⇒ discarded; empty `?tag=` ⇒ absent).
