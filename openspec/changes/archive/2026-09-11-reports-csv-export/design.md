# Design: Reports CSV Export (ticket-list)

## Technical Approach

`GET /api/reports/export` returns a `StreamedResponse`. Inside the callback, `ExportTicketsCsvQuery` walks the result set in **keyset-paginated chunks of 500** (`WHERE t.id > :lastId ORDER BY t.id ASC LIMIT 500`), writing each chunk through `League\Csv\Writer` bound to `php://output` and flushing. Filter SQL is shared with `ListTicketsQuery` via a new pure `TicketFilterBuilder`. Product decisions locked upstream: date range on `t.created_at`, all statuses by default, phones unmasked, no row cap.

## Architecture Decisions

### Decision: keyset chunking, not an unbuffered PDO connection

| Option | Tradeoff | Decision |
|---|---|---|
| A. `MYSQL_ATTR_USE_BUFFERED_QUERY => false` on a dedicated connection | Fewest round-trips, but an unbuffered connection **cannot run any other query while iterating**. The export needs a per-chunk tag lookup mid-iteration, and `doctrine.yaml` defines a single default connection (no `connections:` map), so enabling it requires a first-of-kind multi-connection config plus doubled pooled connections per worker. | Rejected |
| B. Keyset chunking (500/chunk) | ~N/500 extra round-trips; bounded memory; safe on the pooled connection; secondary queries allowed mid-stream; `ORDER BY t.id ASC` with a strict `>` cursor gives no gaps/duplicates. | **Chosen** |

Offset pagination was also rejected: `LIMIT/OFFSET` degrades on deep pages and can skip/repeat rows.

### Decision: extract `TicketFilterBuilder` instead of copying the WHERE block

New `apps/api/src/Application/Query/TicketFilterBuilder.php` — a pure class returning `[array $where, array $params]` for assignment filter, ownership scope, `phone_number_id`, status whitelist (`new|active|waiting|resolved|closed`) and the per-tag correlated `EXISTS` (AND semantics, max 5, `Tag::normalize()`). `ListTicketsQuery` is refactored to call it, emitting clauses in the **same order** so positional `?` params stay aligned; existing ticket-list tests must pass unchanged. The export appends its date-range and keyset clauses after the builder's, in the same order as their params.

### Decision: selective formula escaping

`League\Csv\EscapeFormula` (already vendored; prefixes `'` for `= - + @ \t \r`) is applied via `escapeRecord()` to **`contact_name`, `assigned_agent`, `tags` only** — the free-text columns. `contact_phone` is excluded on purpose: values are stored as `+<digits>`, and a global formatter would visibly corrupt every phone with a literal leading apostrophe (Excel does not strip it on CSV import).

### Decision: role gating is the method-level attribute only

`#[IsGranted('ROLE_SUPERVISOR')]` on `export()` (the class keeps `ROLE_AGENT`; Symfony evaluates both). `security.yaml` declares `ROLE_ADMIN: [ROLE_SUPERVISOR, ROLE_AGENT]`, so admins inherit — **no extra ROLE_ADMIN check is needed**. `#[Route]` attributes are dead here, but `#[IsGranted]` is resolved by a kernel listener and works regardless of routing source.

## Data Flow

    Browser ──GET /api/reports/export?from&to&agent_id&status&tag──▶ ReportingController
      │                                                                    │
      │                                     IsGranted(ROLE_SUPERVISOR), parseDateTime()
      │                                                                    ▼
      │                                                   StreamedResponse(callback)
      │                                                                    │
      │   ┌────────────────── loop until chunk < 500 ──────────────────────┤
      │   │  1. SELECT ... WHERE <filters> AND t.id > :last LIMIT 500      │
      │   │  2. SELECT tt.ticket_id, tg.name ... WHERE tt.ticket_id IN(..) │
      │   │  3. Writer::insertOne() per row  4. flush()                    │
      │   └────────────────────────────────────────────────────────────────┤
      ◀──────────── text/csv bytes arrive incrementally ────────────────────┘

The per-chunk tag query mirrors `ListTicketsQuery`'s existing chunked unread-count lookup.

## Interfaces / Contracts

Columns (header row, UTC ISO-8601 timestamps, `;`-joined tags):
`ticket_id, contact_name, contact_phone, assigned_agent, status, priority, created_at, first_response_at, resolved_at, closed_at, first_response_seconds, resolution_seconds, tags, phone_number_id`

All map to the joins `ListTicketsQuery` already has (`tickets t` → `conversations conv` → `contacts c`, `LEFT JOIN agents a`); the two `*_seconds` columns are `TIMESTAMPDIFF(SECOND, t.created_at, …)`; `tags` comes from the chunk lookup. No new join is introduced.

```yaml
# apps/api/config/routes.yaml (after app_report_reopened)
app_report_export:
    path: /api/reports/export
    methods: [GET]
    controller: App\Controller\ReportingController::export
```

Headers: `Content-Type: text/csv; charset=UTF-8`, `Content-Disposition: attachment; filename="tickets_<from>_<to>.csv"`, `X-Accel-Buffering: no`, `Cache-Control: no-store`. `Writer::setOutputBOM(BOM_UTF8)` for Excel accents; `set_time_limit(0)` before streaming. `from`/`to` are required, as in every other `/api/reports/*` endpoint (parsed by the existing `parseDateTime`).

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/api/src/Application/Query/TicketFilterBuilder.php` | Create | Shared WHERE/params builder |
| `apps/api/src/Application/Query/ExportTicketsCsvQuery.php` | Create | Keyset-chunked row iteration + CSV writing |
| `apps/api/src/Application/Query/ListTicketsQuery.php` | Modify | Delegate filter block to the builder (behavior-preserving) |
| `apps/api/src/Controller/ReportingController.php` | Modify | `export()` action, `StreamedResponse`, `IsGranted('ROLE_SUPERVISOR')` |
| `apps/api/config/routes.yaml` | Modify | `app_report_export` |
| `apps/api/tests/Integration/ReportsCsvExportTest.php` | Create | WebTestCase suite |
| `apps/web/src/app/core/api/reports-api.service.ts` | Modify | `exportTicketsCsv(params): Observable<Blob>` reusing `buildParams()` + `responseType: 'blob'` |
| `apps/web/src/app/core/application/reports/export-tickets-csv.use-case.ts` | Create | Blob → `URL.createObjectURL` → anchor click → `revokeObjectURL` (injects `DOCUMENT`) |
| `apps/web/src/app/features/reports/pages/reports-page/reports-page.component.{ts,html}` | Modify | "Exportar CSV" button in `.reports-page__filters`, gated `@if (authStore.isAdmin() \|\| authStore.isSupervisor())`, `exporting` signal |

Filename is built client-side from the filter dates; no `Access-Control-Expose-Headers` change is needed.

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit (PHPUnit) | `TicketFilterBuilder` clause/param ordering; status whitelist; >5 tags throws | Pure, no DB |
| Integration | Headers, BOM, header row, filtered row set matches the on-screen list, `ROLE_AGENT` → 403, supervisor/admin → 200, formula-injection row (`=cmd`) is `'=cmd` while `+549…` stays intact | `WebTestCase` + `SeedReportsCommand`, capturing streamed bytes via `ob_start(); $response->sendContent(); ob_get_clean();` |
| Integration (load-bearing) | **1200 seeded tickets** → exactly 1200 data rows, strictly ascending unique `ticket_id`, no gap/duplicate across the 500/500/200 chunk boundaries | Same harness; peak-memory assertion kept generous/advisory |
| Frontend | `ExportTicketsCsvUseCase` calls the endpoint and triggers a save with a spy `DOCUMENT` | `ng build` is the enforceable gate (Karma unrunnable per config.yaml) |

## Threat Matrix

N/A — no routing-dispatch, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. Adding an HTTP route entry is ordinary Symfony routing configuration.

## Migration / Rollout

No migration required; no schema or Mercure event-contract change. Removing the `routes.yaml` entry disables the endpoint (404); the frontend button fails closed.

## Open Questions

- [ ] None blocking. Known accepted behaviors: a mid-stream error cannot retract already-sent bytes (logged with a reference id, partial file), and only the ticket's current owner is exported (multi-agent attribution blocked by the missing `Message.sender_agent_id`).
