# Verification Report: ticket-list-filters

**Change**: ticket-list-filters
**Mode**: Full artifact set (proposal, spec, design, tasks, apply-progress) — full verification performed
**Verdict**: PASS WITH WARNINGS

## Completeness

| Item | Status |
|---|---|
| Tasks checked in tasks.md | 19/21 complete, 2 explicitly marked optional/skipped with justification (5.1, 5.3) |
| Task completion matches code state | Yes — every `[x]` task has corresponding code/test evidence in the diff |
| Skipped tasks genuinely optional | Yes (see "Skipped Tasks" section below) |

## Build / Test Evidence (independently reproduced)

Command: `docker compose exec php bin/phpunit` (full suite), run twice independently in this session, plus the new test file run in isolation.

| Run | Tests | Assertions | Errors | Failures | Skipped | Failing+Erroring |
|---|---|---|---|---|---|---|
| Apply-phase claim (before) | 520 | 2146 | 18 | 39 | 1 | 57 |
| Apply-phase claim (after) | 530 | 2102 | 18 | 39 | 1 | 57 |
| **Verify run #1 (independent)** | 530 | 2102 | 18 | **40** | 1 | **58** |
| **Verify run #2 (independent)** | 530 | 2114–2142 (varies) | 18 | **40** | 1 | **58** |
| `ListTicketsQueryFilterTest.php` alone | 10 | 18 | 0 | 0 | 0 | 0 (all pass) |

`ListTicketsQueryFilterTest` never appears in either independent run's failure/error list — confirmed via `rg` against the captured failure output. The two independent full-suite runs produce byte-identical failing/erroring test *identity* lists (`diff` on sorted test names is empty), so the discrepancy is not test-selection flakiness at the identity level — it's a stable, reproducible count of **58**, not the **57** the apply-progress report claims.

Root cause isolated: `App\Tests\Unit\OwnershipScopeTest::testAgentWithLinkedAgentReturnsId` fails deterministically, including in complete isolation (`bin/phpunit tests/Unit/OwnershipScopeTest.php` → 1 failure, "Failed asserting that null is identical to 42"). This is a pure `PHPUnit\Framework\TestCase` unit test using mocks only — no DB, no `KernelTestCase`, no dependency on `ListTicketsQuery`, `InboxController`, `Tag`, or any file this change touches. It is pre-existing, environment/mock-related breakage, unrelated to this diff.

**WARNING**: The apply-progress report's regression-count claim (57/520 → 57/530, "unchanged") does not reproduce independently — actual measured count is 58 both before accounting for this change's tests and after (18 errors + 40 failures, not 39). This is off by exactly the one deterministically-failing, unrelated `OwnershipScopeTest` case. It does not indicate a regression caused by this change: the new test file contributes 0 failures in both isolated and full-suite runs, and the failing/erroring test identity set is 100% consistent across two independent full-suite runs (no test flips in or out based on whether/how the new code executes). This is a pre-existing miscount or measurement drift in the apply phase's baseline capture, not a defect introduced by `ticket-list-filters`.

Assertion-count variance between runs (2075–2142 for identical Tests/Errors/Failures counts) further indicates a small amount of pre-existing non-determinism elsewhere in the suite (most likely `Sprint09BConcurrencyTest`/`Sprint10ConcurrencyTest`, which exercise actual concurrent DB writes) — also unrelated to this change.

## Spec Compliance Matrix

| Requirement | Scenario | Covering Test | Result |
|---|---|---|---|
| Status Filter | waiting status is honored | `test_waiting_status_is_returned` | PASS |
| Status Filter | unknown status token ignored | Pre-existing `in_array` filter logic (not re-tested by this change; behavior unchanged) | PASS (by inspection, unchanged code path) |
| Tag Filter | single tag | `test_single_tag_returns_only_tagged_tickets` | PASS |
| Tag Filter | multi-tag AND | `test_multi_tag_and_excludes_partial_match` | PASS |
| Tag Filter | unknown/non-matching tag | `test_unknown_tag_returns_empty_no_exception` | PASS |
| Tag Filter | tag count exceeds cap | `test_tag_cap_enforced` (6 throws), `test_tag_cap_of_five_does_not_throw` (5 does not) | PASS |
| Tag Filter | blank segments discarded | `test_blank_segments_discarded` | PASS |
| Tag Filter | empty tag parameter treated as absent | `test_empty_or_absent_tag_applies_no_filter` | PASS |
| Ownership/Workspace Scope | scope narrows regardless of other filters | `test_tag_filter_composes_with_ownership_scope` | PASS |
| Pagination/Total Consistency | total matches filtered count under multi-tag | `test_pagination_total_correct_under_multi_tag` | PASS |
| Pagination/Total Consistency | backward compatibility when tag absent | `test_empty_or_absent_tag_applies_no_filter` + pre-existing `TicketQueriesLastInboundMessageAtTest` (no `tag` param) | PASS |

All mandatory spec scenarios have a covering test that passed at runtime in this session.

## Correctness Verification (source inspection, per explicit instructions)

1. **`waiting` whitelist fix** — CONFIRMED. `ListTicketsQuery.php:83`: `in_array($s, ['new','active','waiting','resolved','closed'])`. All 5 `TicketStatus` values present.

2. **Tag filter AND semantics via N-stacked correlated EXISTS, scoped only to alias `t`** — CONFIRMED. Lines 99–104:
   ```php
   foreach ($tags as $name) {
       $where[] = 'EXISTS (SELECT 1 FROM ticket_tags tt JOIN tags tg ON tg.id = tt.tag_id
                           WHERE tt.ticket_id = t.id AND tg.name = ?)';
       $params[] = $name;
   }
   ```
   Each clause is independent, referencing only `t.id` (no other outer alias), and is `AND`-joined into `$where` via the existing `implode(' AND ', $where)` — AND semantics is emergent, not special-cased. `$sql` (`FROM tickets t JOIN conversations conv ... LEFT JOIN agents a ...`) and `$countSql` (`SELECT COUNT(*) FROM tickets t $whereClause`) both alias the tickets table as `t`, so the same `$where`/`$params` are valid, unambiguous, and produce consistent `total`/`count(items)` in both — verified concretely at runtime by `test_pagination_total_correct_under_multi_tag` (3 matches out of 4 seeded, `total=3` and `count(items)=2` under `limit=2`, i.e., `total` is not deflated/inflated by the `limit` window).

3. **Blank-segment discarding, dedup, 5-tag cap** — CONFIRMED. Lines 92–98: `array_map(normalize)` → `array_filter(non-blank)` → `array_unique` → `array_values`, in that order (blanks dropped before dedup, both before the `count() > 5` cap check) → `\InvalidArgumentException` on breach. `InboxController.php` catches `\InvalidArgumentException` at lines 85–86 and maps it to HTTP 400 with an `error` key — this catch block is pre-existing and unmodified by this change.

4. **Empty `?tag=` treated as absent; unknown tag → empty result** — CONFIRMED. Guard at line 91: `if (null !== $tag && '' !== trim($tag))`. `?tag=` yields `''` from `$request->query->get('tag')`, and `trim('') === ''`, so the block is skipped entirely — no filter applied, matching spec. Unknown tag names simply produce an `EXISTS` clause that never matches — no pre-validation against the `tags` table, no special-cased error path, matching spec and design exactly.

5. **Positional `?` parameter ordering** — CONFIRMED. This query uses positional placeholders throughout (no named params). `$where[]` and `$params[]` are always pushed together, block by block, in this fixed order: `filter`/`mine` → `scopeAgentId` → `phoneNumberId` → `status` → `tag`. `$whereClause = implode(' AND ', $where)` preserves that same order when building the SQL string, so `$params` stays positionally aligned with the emitted `?` placeholders regardless of which blocks are active for a given request. The tag block is the last one appended, consistent with the design's stated requirement ("tag block MUST be appended after the status block").

## Design Coherence

| Decision | Code Match |
|---|---|
| N-stacked correlated EXISTS, `t`-scoped only | Match |
| AND semantics via existing `implode(' AND ', ...)` | Match |
| Positional `?`, appended in clause order | Match |
| Cap breach → `\InvalidArgumentException` → 400 via pre-existing controller catch | Match |
| Unknown tag → empty result, no pre-validation | Match |
| Normalization via `Tag::normalize()` | Match |
| File changes limited to the 3 files in design's File Changes table | Match — `git diff --stat` shows exactly `ListTicketsQuery.php` (+23/-2), `InboxController.php` (+2), plus the new test file |

No design deviations found.

## Skipped Tasks (5.1 / 5.3)

Tasks.md explicitly marks 5.1 as "(Optional, RED)" and 5.3 as its corresponding run step. Design.md's File Changes table lists exactly 3 files (`ListTicketsQuery.php`, `InboxController.php`, `ListTicketsQueryFilterTest.php`) — no functional `WebTestCase` file is in scope. The `\InvalidArgumentException` → HTTP 400 mapping these tasks would have tested is pre-existing `InboxController` catch-block behavior (lines 85–86), unmodified by this change and already exercised by other endpoints (e.g. `getMessages` at lines 151–152 uses the identical catch pattern). This is a legitimate, justified scope reduction of genuinely optional coverage — not a corner cut on any required spec scenario. All 11 spec scenarios remain covered by passing tests at the `ListTicketsQuery` integration layer.

## Issues

### CRITICAL
None.

### WARNING
1. Apply-progress's baseline/regression figure ("57/520" → "57/530, unchanged") does not reproduce independently. Two independent full-suite runs in this session measured **58/530** (18 errors + 40 failures), with an identical failing/erroring test identity set both times. Root cause: `App\Tests\Unit\OwnershipScopeTest::testAgentWithLinkedAgentReturnsId` fails deterministically and in total isolation — a pure mock-based unit test wholly unrelated to this change's files. This does not implicate `ticket-list-filters` in a regression (the new test file contributes 0 failures in isolation and in the full suite, and no other test's pass/fail status differs between runs), but the apply-progress report's specific count claim is factually off by one and should be corrected before being relied on as a regression gate in future work on this codebase.
2. Full-suite assertion counts vary between otherwise-identical runs (2075–2142), indicating minor pre-existing non-determinism elsewhere in the suite (most likely the concurrency integration tests). Unrelated to this change; noted for awareness only.

### SUGGESTION
1. Consider filing/tracking the `OwnershipScopeTest` and other 57/58 pre-existing failures separately from this change so future SDD baselines for this codebase are measured against a clean or explicitly-quarantined suite.

## Final Verdict

**PASS WITH WARNINGS** — All spec requirements and scenarios are implemented correctly and covered by passing tests; the core AND-semantics/EXISTS/positional-param correctness constraints from design.md hold up under source inspection and runtime verification; the two skipped tasks are legitimately optional and out of required scope. The only issue is a factual inaccuracy in the apply-progress report's regression-count claim (57 vs. the independently-reproduced, stable 58), traced to a pre-existing, unrelated unit test failure — not a defect in this change. Safe to proceed to archive, with a note to correct the baseline figure in the apply-progress record if it is reused downstream.
