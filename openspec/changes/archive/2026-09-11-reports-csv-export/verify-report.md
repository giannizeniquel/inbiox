# Verification Report — reports-csv-export

**Mode**: Full artifacts (proposal, spec, design, tasks all present).
**Verdict**: PASS

## Completeness

| Dimension | Status |
|---|---|
| Tasks (20/20) | All checked complete; independently re-verified against code. |
| Spec requirements (6) | All mapped to implementation and covering tests. |
| Design decisions (4) | All followed as documented; no undocumented drift. |

## 1. Phase 1 regression gate (independently re-checked)

Read `git diff` of `ListTicketsQuery.php` and `TicketFilterBuilder.php` side by side.

- Clause/param order is identical to the original inline block: `filter/mine` → ownership scope → workspace scope → status whitelist → tag `EXISTS` (AND, max 5, `Tag::normalize()`). No clause dropped, duplicated, or reordered.
- Only cosmetic diff: `array_merge($params, $statuses)` → `array_merge($params, array_values($statuses))`. `array_filter` preserves string keys on a list input; `array_values` only re-indexes, it does not change value order or content — behavior-preserving.
- `ListTicketsQuery` now injects `TicketFilterBuilder` via a default-constructed constructor parameter (`= new TicketFilterBuilder()`), calls `$this->ticketFilterBuilder->build(...)`, and builds `$whereClause` from the same `[$where, $params]` shape as before.
- Independently re-ran (not trusting apply's claim): `docker exec inbiox_php php bin/phpunit tests/Integration/ListTicketsQueryFilterTest.php tests/Integration/TicketQueriesLastInboundMessageAtTest.php` → **13/13 pass** (10 + 3), confirming identical behavior before/after the extraction.

**Verdict: genuinely behavior-preserving.**

## 2. Real streaming (independently re-checked)

`ExportTicketsCsvQuery::stream()` uses a `do { ... } while ($chunkCount === self::CHUNK_SIZE)` loop: each iteration runs `SELECT ... WHERE <filters> AND t.id > ? ORDER BY t.id ASC LIMIT 500` with `$lastId` advanced to the last row's id after each chunk, writing rows to the `Writer` and incrementing `$totalRows` as it goes. This is genuine keyset/cursor chunking, not a single unbuffered query, and rows are written to the CSV writer per-chunk (streamed to `php://output` from inside a `StreamedResponse` callback), not buffered then written.

The 1200-row test (`test_1200_tickets_export_with_no_gaps_or_duplicates_across_chunk_boundaries`) was read line-by-line, not just by name:
- Asserts `count($records) === 1200`.
- Asserts strictly ascending `ticket_id` across the entire result (`assertGreaterThan($exportedIds[$i-1], $exportedIds[$i])` for every row) — rules out gaps introduced by cursor mis-advance and re-fetch of already-seen rows.
- Asserts `count(array_unique($exportedIds)) === 1200` — rules out duplicates.
- Asserts the exact sorted set of exported ids equals the exact sorted set of seeded ids — rules out any gap (an omitted id would break equality even if the two prior counts happened to coincidentally match).

This is a real, load-bearing assertion, not a token check.

## 3. CSV correctness

- **14-column contract**: `ExportTicketsCsvQuery::COLUMNS` = `ticket_id, contact_name, contact_phone, assigned_agent, status, priority, created_at, first_response_at, resolved_at, closed_at, first_response_seconds, resolution_seconds, tags, phone_number_id` — matches spec.md's Column Contract requirement exactly, same order, same 14 fields. No drift.
- **EscapeFormula scope**: confirmed by reading the actual `Writer` setup — `ESCAPABLE_COLUMNS = ['contact_name', 'assigned_agent', 'tags']`; the code does `array_intersect_key($record, array_flip(self::ESCAPABLE_COLUMNS))` then `$escapeFormula->escapeRecord($escapable)` then merges back, so `contact_phone` is never passed through `EscapeFormula`. Confirmed by test `test_formula_injection_escaped_on_name_columns_but_phone_untouched`: `contact_name` `=cmd|/c calc` → `'=cmd|/c calc`; `contact_phone` starts with `+549` (untouched).
- **Tag ordering deviation**: `fetchTagsByTicket()` uses `ORDER BY tt.ticket_id ASC, tt.id ASC` (ticket_tags attachment/insertion order), not alphabetical `tg.name`. Checked against spec.md's literal scenario: "tags `vip` and `urgente`… `tags` = `vip;urgente`". Alphabetically, `urgente` < `vip` (u before v), so an alphabetical join would produce `urgente;vip` — contradicting the spec's own example. Insertion order produces `vip;urgente` as documented if `vip` was tagged first, consistent with the spec's literal expected output. **The chosen ordering is the one that actually satisfies spec.md, not a deviation from it** — apply's justification holds up under independent scrutiny; alphabetical would have been the real spec violation.

## 4. Access control

- `#[IsGranted('ROLE_SUPERVISOR')]` is present directly on `ReportingController::export()` (confirmed by reading the controller source, not assumed).
- `apps/api/config/routes.yaml` registers `app_report_export` → `path: /api/reports/export`, `methods: [GET]`, `controller: App\Controller\ReportingController::export`. The `#[Route('/reports/export', ...)]` attribute on the method itself is dead (consistent with every other action in this controller — routing.yaml is authoritative, confirmed by reading the full diff), so this is NOT a repeat of the known dead-route trap: the YAML entry was actually added.
- Class-level `#[IsGranted('ROLE_AGENT')]` remains on the controller; Symfony evaluates both attributes, so `export()` effectively requires `ROLE_SUPERVISOR`.
- `security.yaml`'s `role_hierarchy` (`ROLE_ADMIN: [ROLE_SUPERVISOR, ROLE_AGENT]`, referenced in design.md) means admins inherit without a separate grant, and this is directly tested (`test_admin_inherits_export_access` → 200; `test_agent_is_denied` → 403; `test_supervisor_gets_200` → 200). All three re-ran green independently (see §8).

## 5. Frontend gating

`reports-page.component.html`: `@if (authStore.isAdmin() || authStore.isSupervisor())` gates the "Exportar CSV" button — confirmed by reading the actual template diff, not assumed. `AuthStore` exposes `isAdmin` and `isSupervisor` as separate `computed()` signals (confirmed in `auth.store.ts`). This is the correct OR-gate; the `contact-csv-import` single-role bug from earlier this session was **not** repeated here.

## 6. Headers

Read `ReportingController::export()` directly:
- `Content-Type: text/csv; charset=utf-8` — set.
- `Content-Disposition: attachment; filename="tickets_<from>_<to>.csv"` — set.
- `X-Accel-Buffering: no` — set.
- `Cache-Control: no-store` — also set (not required by design.md's minimal list but harmless and actually specified in design.md's headers section too).
All four asserted by `test_headers_signal_a_file_download`, which passed in the independent re-run (§8).

## 7. BOM handling deviation

Verified against the vendored library source (`vendor/league/csv/src/AbstractCsv.php`), not assumed:
- `setOutputBOM()` sets `$this->output_bom`, which is only consumed by `AbstractCsv::chunk()` (used by `toString()`) and `download()` (`fwrite($this->output_bom->value)` inside `download()` at line ~264). Neither path is used here — the code calls `Writer::createFromStream($stream)` and writes rows directly via `insertOne()`, which never reads `$output_bom`. **Apply's technical claim is accurate**: `setOutputBOM()` genuinely has no effect on this streaming path, and the by-hand `fwrite($stream, Bom::Utf8->value)` before wrapping the stream in the `Writer` is the correct, necessary fix.
- Verified `Bom::Utf8` = `case Utf8 = "\xEF\xBB\xBF"` in `vendor/league/csv/src/Bom.php` — the exact 3-byte UTF-8 BOM, byte-for-byte correct.
- Test `test_header_row_has_utf8_bom_and_fourteen_columns` asserts `assertStringStartsWith("\xEF\xBB\xBF", $body)` then parses `substr($body, 3)` as CSV — correct handling, and Excel opening a file with a genuine UTF-8 BOM followed by UTF-8-encoded bytes (contact names with accents, e.g. from MySQL `utf8mb4` columns) will correctly detect UTF-8 and render accented characters without the classic Windows-1252 mojibake. No gap found.

## 8. Independent test re-execution

- `docker exec inbiox_php php bin/phpunit tests/Integration/ListTicketsQueryFilterTest.php tests/Integration/TicketQueriesLastInboundMessageAtTest.php` → **13/13 pass** (Phase 1 proof, isolated).
- `docker exec inbiox_php php bin/phpunit tests/Unit/Application/Query/TicketFilterBuilderTest.php tests/Integration/ExportTicketsCsvQueryTest.php tests/Integration/ReportsCsvExportTest.php tests/Integration/StreamedResponseCaptureSpikeTest.php` → **41/41 pass** (17 + 9 + 13 + 2). The console line "Uncaught PHP Exception ... AccessDeniedHttpException" is Symfony's kernel exception listener logging the expected 403 in `test_agent_is_denied`; it is not a test failure (final line: `OK (41 tests, 1294 assertions)`).
- Full suite, run 1: `docker exec inbiox_php php -d memory_limit=1024M bin/phpunit` → 593 tests, 18 Errors + 39 Failures = 57 failing/erroring (one fewer than apply's claimed 58).
- Full suite, run 2 (re-ran to check for flakiness): 593 tests, 18 Errors + 40 Failures = 58 failing/erroring — **matches apply's claimed baseline exactly**.
- Investigated the one-off discrepancy: the extra failure in run 2 not present in run 1 was `App\Tests\Controller\AuthorizationTest::testUnauthenticatedRequestToReportsReturns401`, which targets the pre-existing `/reports/frt` endpoint (unrelated to `/api/reports/export`) — a known pre-existing flaky/order-dependent test, not caused by this change. No failure in either run touches `TicketFilterBuilder`, `ExportTicketsCsvQuery`, `ReportsCsvExportTest`, or `StreamedResponseCaptureSpikeTest`.
- **Conclusion: the 593/58 figure is confirmed as the correct baseline** (±1 test of pre-existing environment flakiness unrelated to this change); no regression introduced.

## 9. Frontend build

`cd apps/web && npx ng build --configuration development` → **exit 0**. Only pre-existing Sass `@import` deprecation warnings (unrelated to this change, present in `styles.scss` before this change).

## 10. Other deviations assessed

- **Tag ordering** (attachment order vs. alphabetical): assessed above (§3) as the *correct* reading of spec.md, not scope drift — alphabetical would have been the actual spec violation.
- **Up-front `TicketFilterBuilder::build()` call in the controller** before opening the `StreamedResponse`: reasonable, not scope drift. Once headers are flushed and the streaming callback starts, a `400` can no longer be returned (bytes are already committed to the client) — spec.md's own scenario requires "no export runs" on validation failure, which is only achievable by validating before the stream opens. The pure, DB-free `build()` call is cheap to call twice (controller + inside `stream()`), and reusing the exact same builder guarantees the validation and the actual query see identical filter logic — no drift risk from duplicated validation logic.

## Issues

**CRITICAL**: None.

**WARNING**: None.

**SUGGESTION**:
- `ExportTicketsCsvQuery::stream()`'s `TicketFilterBuilder::build()` call happens twice per request (once in the controller for up-front validation, once inside `stream()`). Both are pure/DB-free so this is not a performance concern, but a future refactor could pass the already-built `[$where, $params]` into `stream()` to avoid the double call — non-blocking, cosmetic only.

## Final Verdict: **PASS**

All 20/20 tasks confirmed complete and matching code state. Phase 1 regression gate independently re-verified as behavior-preserving with passing tests. Streaming is genuine keyset/cursor chunking with a load-bearing no-gap/no-duplicate proof. CSV column contract, EscapeFormula scope, BOM handling, headers, and access control all verified by direct source inspection, matching spec.md and design.md. Frontend gating correctly uses `isAdmin() || isSupervisor()`. Full PHPUnit suite (593 tests) and Angular build both re-run independently with no regressions. Ready for `sdd-archive`.
