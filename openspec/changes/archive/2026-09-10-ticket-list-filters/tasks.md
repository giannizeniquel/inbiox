# Tasks: Ticket List Filters

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~180-230 (prod ~20; tests ~160-210) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | PR | Focused test | Harness | Rollback |
|---|---|---|---|---|---|
| 1 | `waiting` fix + AND tag filter + controller wiring | PR 1 (single) | `bin/phpunit tests/Integration/ListTicketsQueryFilterTest.php` | `bin/phpunit` full-suite vs ~57/455 baseline | Revert `ListTicketsQuery.php`/`InboxController.php` hunks; test file deletable independently |

## Phase 1: RED — Status Regression

- [x] 1.1 Create `apps/api/tests/Integration/ListTicketsQueryFilterTest.php`: `KernelTestCase`; `setUp()` truncates `outbox_events`, `ticket_events`, `ticket_tags`, `messages`, `assignments`, `tickets`, `conversations`, `contacts` (FK-safe, per `TicketQueriesLastInboundMessageAtTest`); resolve `ListTicketsQuery` from container.
- [x] 1.2 `test_waiting_status_is_returned()`: seed one ticket per `TicketStatus`; assert `status=waiting` returns only it. Confirm FAIL (waiting missing from whitelist).

## Phase 2: GREEN — Status Whitelist Fix

- [x] 2.1 `ListTicketsQuery.php:79`: add `'waiting'` to the `in_array` allow-list.
- [x] 2.2 Run 1.2; PASS. Confirm suite failure count unchanged (57/520 baseline).

## Phase 3: RED — Tag Filter Tests

- [x] 3.1 `test_single_tag_returns_only_tagged_tickets()`.
- [x] 3.2 `test_multi_tag_and_excludes_partial_match()` — core anti-regression: ticket with 1 of 2 tags is EXCLUDED.
- [x] 3.3 `test_pagination_total_correct_under_multi_tag()` — seed > `limit` matches; assert `total` and `count(items)` together (catches `$sql`/`$countSql` divergence).
- [x] 3.4 `test_unknown_tag_returns_empty_no_exception()`.
- [x] 3.5 `test_blank_segments_discarded()` — `tag=vip,,urgente` == `tag=vip,urgente`.
- [x] 3.6 `test_empty_or_absent_tag_applies_no_filter()`.
- [x] 3.7 `test_tag_cap_enforced()` — 6 tags throws `\InvalidArgumentException`; 5 does not (`test_tag_cap_of_five_does_not_throw`).
- [x] 3.8 `test_tag_filter_composes_with_ownership_scope()` — scoped agent + tag ⇒ intersection only.
- [x] 3.9 Confirmed all 8 tag tests FAIL/ERROR before implementation (`Unknown named parameter $tag`).

## Phase 4: GREEN — Tag Filter Implementation

- [x] 4.1 `ListTicketsQuery.php`: add `use App\Domain\Tag\Tag;` + trailing `?string $tag = null` param.
- [x] 4.2 After `$status` block: parse → `Tag::normalize()` → drop blanks → dedupe → cap(5) (`\InvalidArgumentException` if >5) → append one correlated `EXISTS (...WHERE tt.ticket_id = t.id AND tg.name = ?)` per tag to `$where[]`/`$params[]`.
- [x] 4.3 Ran 3.1–3.8 (10 tests incl. 1.2); all PASS. Suite failures unchanged (57/530), no new unrelated failures.

## Phase 5: Wiring — Controller

- [~] 5.1 (Optional, RED) `WebTestCase`: `GET /tickets?tag=` with 6 values returns HTTP 400 with `error` key. **Skipped** — out of design's File Changes list (3 files only); the `\InvalidArgumentException` → 400 mapping is pre-existing generic controller behavior already exercised by other endpoints. Not required for the mandatory spec scenarios, which are fully covered at the `ListTicketsQuery` integration layer.
- [x] 5.2 (GREEN) `InboxController.php` (~L48): read `$tag = $request->query->get('tag');`; pass `tag: $tag` to `execute()`.
- [~] 5.3 N/A — 5.1 not added, nothing to run.

## Phase 6: Regression Verification

- [x] 6.1 Ran full `bin/phpunit`; 57/530 failures+errors (18 errors + 39 failures), identical to the 57/520 live baseline measured before any change. No new unrelated failures.
- [x] 6.2 Confirmed `GET /tickets` with no `tag` is unaffected: `test_empty_or_absent_tag_applies_no_filter` plus pre-existing `TicketQueriesLastInboundMessageAtTest::testListTicketsQueryExposesLastInboundMessageAt` (no `tag` param) still pass unchanged.

### Ordering Notes

1→2 and 3→4 are strict RED→GREEN pairs. Tasks 1.1/1.2 and 3.1–3.9 may be authored in parallel (independent test methods, same file, no shared dependency). Phase 5 depends on Phase 4; Phase 6 is final and depends on all prior phases.
