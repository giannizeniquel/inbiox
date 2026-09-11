# Tasks: Reports CSV Export (ticket-list)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | Backend refactor ~250, Backend new ~510, Frontend ~110 → **~870 total** |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (refactor) → PR 2 (export feature) → PR 3 (frontend) |
| Delivery strategy | single-pr (session-cached) |
| Chain strategy | pending — orchestrator must ask user |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

**Escalation**: session's 800-line budget is exceeded (~870 est.) even before frontend; single-pr must NOT be assumed silently — same pattern as `contact-csv-import`. Orchestrator must present this split and ask the user for a chain strategy before `sdd-apply`.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Extract `TicketFilterBuilder`, behavior-preserving | PR 1 | `bin/phpunit tests/Unit/Application/Query/TicketFilterBuilderTest.php tests/Integration/ListTicketsQueryFilterTest.php tests/Integration/TicketQueriesLastInboundMessageAtTest.php` | N/A — pure/DB unit+integration only | Revert `TicketFilterBuilder.php` + `ListTicketsQuery.php` diff; no route/schema touched |
| 2 | `ExportTicketsCsvQuery` + controller + route + tests | PR 2 | `bin/phpunit tests/Integration/ReportsCsvExportTest.php` | `ob_start(); $response->sendContent(); ob_get_clean();` capture harness | Remove `app_report_export` from `routes.yaml` → 404, no schema change |
| 3 | Frontend export button + use case | PR 3 | `cd apps/web && ng build` | N/A — Karma unrunnable per config.yaml; `ng build` is the gate | Revert component/service/use-case files; button fails closed |

## Phase 1: Backend Refactor — TicketFilterBuilder (PR 1)

- [x] 1.1 RED: `tests/Unit/Application/Query/TicketFilterBuilderTest.php` — clause/param order, status whitelist, tag AND-semantics EXISTS, >5 tags throws
- [x] 1.2 GREEN: create `TicketFilterBuilder.php`, moving `ListTicketsQuery`'s WHERE-building block verbatim (filter/scope/status/tag)
- [x] 1.3 REGRESSION: refactor `ListTicketsQuery::execute()` to delegate to the builder, same clause/param order; re-run `ListTicketsQueryFilterTest.php` + `TicketQueriesLastInboundMessageAtTest.php` unchanged, green
- [x] 1.4 Confirm no new failures vs the re-confirmed live baseline

## Phase 2: Export Query Core (PR 2a)

- [x] 2.1 SPIKE RED/GREEN: prove `ob_start()/sendContent()/ob_get_clean()` captures a trivial `StreamedResponse` in `WebTestCase` before chunking logic
- [x] 2.2 RED: `ExportTicketsCsvQuery` tests — keyset chunk shape (`t.id > :lastId LIMIT 500`), date-range clause appended after builder params
- [x] 2.3 GREEN: create `ExportTicketsCsvQuery.php` — reuses `TicketFilterBuilder`, adds `created_at` range + cursor, writes via `League\Csv\Writer`, `EscapeFormula` on `contact_name`/`assigned_agent`/`tags` only

## Phase 3: Controller + Routing (PR 2b)

- [x] 3.1 RED: `ReportsCsvExportTest.php` — 200 for supervisor/admin, 403 for agent, headers, BOM, 14-column header row
- [x] 3.2 GREEN: add `app_report_export` to `apps/api/config/routes.yaml`
- [x] 3.3 GREEN: add `export()` to `ReportingController` — `#[IsGranted('ROLE_SUPERVISOR')]`, `StreamedResponse`, `set_time_limit(0)`, `X-Accel-Buffering: no`

## Phase 4: Filter & Load-Bearing Proofs (PR 2c)

- [x] 4.1 RED: date-range on `created_at`, no-status-includes-all, tag AND + 6-tag rejection
- [x] 4.2 RED: formula-injection row (`=cmd` → `'=cmd`; `+549…` phone untouched)
- [x] 4.3 RED (load-bearing): seed 1200 tickets → exactly 1200 rows, strictly ascending unique `ticket_id`, no gap/dup across 500/500/200 chunk boundaries
- [x] 4.4 GREEN: iterate query/controller until Phase 3–4 all green

## Phase 5: Frontend Export Trigger (PR 3)

- [x] 5.1 Add `exportTicketsCsv(params): Observable<Blob>` to `reports-api.service.ts` (reuse `buildParams()`, `responseType: 'blob'`)
- [x] 5.2 Create `export-tickets-csv.use-case.ts` — blob → `URL.createObjectURL` → anchor click → `revokeObjectURL`, injects `DOCUMENT`
- [x] 5.3 Add "Exportar CSV" button + `exporting` signal to `reports-page.component.ts`/`.html`, gated `authStore.isAdmin() || authStore.isSupervisor()`
- [x] 5.4 Verify `ng build` passes

## Phase 6: Regression Close-out

- [x] 6.1 Run full `bin/phpunit`; failing/erroring count must not exceed re-confirmed live baseline
- [x] 6.2 Confirm `ListTicketsQueryFilterTest.php` + `TicketQueriesLastInboundMessageAtTest.php` unchanged and green
