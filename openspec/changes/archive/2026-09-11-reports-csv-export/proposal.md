# Proposal: Reports CSV Export (ticket-list)

## Intent

Requirement #10 (gap analysis Tier 1 item #5) asks to export answered conversations filtered by date range, agent and status. Today reporting is JSON-on-screen only; supervisors rebuild lists by hand for audits and payroll/SLA reviews. This adds one row-per-ticket CSV download reusing the filters already proven in `ListTicketsQuery`.

## Scope

### In Scope
- `GET /api/reports/export` (new `app_report_export` entry in `config/routes.yaml`) accepting `from`, `to`, `agent_id`, `status`, `tag`; `phone_number_id` from `WorkspaceScope`.
- `#[IsGranted('ROLE_SUPERVISOR')]` on the action only — bulk PII export is gated tighter than the all-`ROLE_AGENT` aggregate endpoints. Role hierarchy already grants admins.
- Streamed `text/csv` + `Content-Disposition: attachment` response. No row cap, no full buffering.
- Columns: `ticket_id`, `contact_name`, `contact_phone`, `assigned_agent`, `status`, `priority`, `created_at`, `first_response_at`, `resolved_at`, `closed_at`, `first_response_seconds`, `resolution_seconds`, `tags` (`;`-joined), `phone_number_id`. Timestamps UTC ISO-8601.
- Frontend "Exportar CSV" button on the reports page, reusing its existing filter signals; authenticated blob fetch → `URL.createObjectURL` → anchor click. Visible only when `isAdmin() || isSupervisor()`.

### Out of Scope
- Per-message multi-agent attribution and per-agent message counts (requirement #11): blocked by the missing `Message.sender_agent_id` column (Tier 2 #10). Only the ticket's **current owner** is exported. Accepted, documented limitation.
- Message bodies/transcripts; XLSX; aggregate-KPI-dashboard export; `cliente-empresa`, `equipo`, `tipo de consulta` filters (non-existent domain concepts).

## Capabilities

### New Capabilities
- `reports-export`: supervisor-gated, filtered, streamed ticket-list CSV export.

### Modified Capabilities
- None.

## Approach

New `ExportTicketsCsvQuery` (Application/Query) lifting `ListTicketsQuery`'s WHERE construction (status whitelist, tag `EXISTS` AND-semantics max 5, workspace scope) minus pagination, plus date-range bounds on `t.created_at` and an aggregated tag subquery. Controller returns a Symfony `StreamedResponse`; inside the callback `League\Csv\Writer::createFromStream(fopen('php://output','w+'))` (dependency already present) writes the header then one row per DB row, flushing periodically. Raw DBAL row iteration is chosen over ORM `toIterable()`: no entity hydration or identity-map growth, and the reporting layer is already raw SQL.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/config/routes.yaml` | Modified | `app_report_export` → `/api/reports/export` |
| `apps/api/src/Controller/ReportingController.php` | Modified | `export()` action, `StreamedResponse`, per-action `IsGranted` |
| `apps/api/src/Application/Query/ExportTicketsCsvQuery.php` | New | Filtered row-streaming query + CSV writing |
| `apps/api/tests/Integration/` | New | WebTestCase: filters, RBAC 403, header row, CSV escaping |
| `apps/web/.../reports-page/reports-page.component.{ts,html}` | Modified | Export button + role guard |
| `apps/web/.../reports` provider/use case | New | Authenticated blob download |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| PDO MySQL buffers results by default, so row iteration alone may not stream | High | Design must pick: unbuffered dedicated connection, or keyset chunking (`WHERE t.id > :last LIMIT n`) |
| Long export exceeds PHP/proxy timeouts on wide ranges | Med | Early flush + `set_time_limit(0)`; document server timeout expectations |
| Partial file on mid-stream error (headers already sent) | Med | Log with reference id; CSV cannot be retracted — document as known behavior |
| CSV formula injection (`=`, `+`, `@` in names) | Med | Escape leading formula characters |
| Backend+frontend combo over 800-line review budget | Med | `sdd-tasks` slices backend / frontend |

## Rollback Plan

Revert the commit. Removing the `routes.yaml` entry alone disables the endpoint (404) with no schema or data change; no migration exists to undo. The hidden frontend button fails closed.

## Dependencies

- `league/csv ^9` — already installed.
- `Message.sender_agent_id` (Tier 2 #10) — prerequisite for a later requirement-#11 follow-up, not for this change.

## Success Criteria

- [ ] A supervisor exports a CSV whose rows match the on-screen filtered ticket list for the same `from`/`to`/`agent`/`status`/`tag`.
- [ ] `ROLE_AGENT` receives 403; the button is hidden for agents and shown for admins.
- [ ] Export of a large range completes without exhausting `memory_limit` (verified by peak-memory assertion or profiling).
- [ ] CSV opens cleanly in Excel/Sheets with correct accents and quoted values.

## Proposal question round

Answers 1–2 (supervisor gating, no row cap) are locked. Remaining open product questions — answer, correct, or skip:

1. Should the date range filter on **ticket creation** (`created_at`) or on **resolution** (`resolved_at`)? "Conversaciones respondidas" hints at resolution; this proposal assumes `created_at` for consistency with existing `/reports/*`.
2. Should the export include unassigned and still-open tickets, or only resolved/closed ones?
3. Is exporting raw contact phone numbers in full acceptable, or should they be masked for anyone below admin?
