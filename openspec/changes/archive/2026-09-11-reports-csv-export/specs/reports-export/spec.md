# Reports Export Specification

## Purpose

Defines `GET /api/reports/export`: a supervisor-gated, filtered, streamed CSV export of the ticket list, reusing `ListTicketsQuery`'s filter and workspace-scope conventions (see `ticket-list-filters`, `authorization`). Full multi-agent per-message attribution and any masking/aggregate-KPI export are explicitly out of scope (structural gap: no `Message.sender_agent_id`).

## Requirements

### Requirement: Export Filter Contract

The system MUST accept `from`, `to` (date range applied to `t.created_at`), `agent_id`, `status`, `tag`, with `phone_number_id` resolved from `WorkspaceScope` (not a query param). Filter semantics MUST match `ListTicketsQuery` exactly: `status` restricted to `new`/`active`/`waiting`/`resolved`/`closed` with unknown tokens dropped; `tag` is AND-semantics, normalized, capped at 5 non-blank tags with over-cap rejected as a validation error; workspace/ownership scope narrows and is never bypassable. When no `status` is given, ALL statuses are included (same universe as the unfiltered ticket list).

#### Scenario: Date range filters on created_at

- GIVEN tickets created on 2026-01-01 and 2026-02-01
- WHEN `GET /api/reports/export?from=2026-01-01&to=2026-01-31` is called
- THEN only the ticket created on 2026-01-01 appears in the CSV

#### Scenario: No status filter includes every status

- GIVEN tickets in `new`, `active`, `waiting`, `resolved`, and `closed` states within range
- WHEN `GET /api/reports/export?from=...&to=...` is called with no `status` param
- THEN rows for all five statuses are present in the output

#### Scenario: Tag AND-semantics and 5-tag cap apply identically to export

- WHEN `GET /api/reports/export?tag=a,b,c,d,e,f` (6 tags) is called
- THEN the request is rejected as a validation error and no export runs

### Requirement: Role Gating

The endpoint MUST require `ROLE_SUPERVISOR` via `#[IsGranted('ROLE_SUPERVISOR')]`. Because `role_hierarchy` declares `ROLE_ADMIN` as inheriting `ROLE_SUPERVISOR`, admins MUST also succeed with no separate grant.

#### Scenario: Supervisor exports successfully

- GIVEN a user with `ROLE_SUPERVISOR`
- WHEN `GET /api/reports/export` is called
- THEN the response is 200 with a streamed CSV body

#### Scenario: Admin inherits export access

- GIVEN a user with `ROLE_ADMIN` only (no explicit `ROLE_SUPERVISOR`)
- WHEN `GET /api/reports/export` is called
- THEN the response is 200 (role hierarchy grants `ROLE_SUPERVISOR`)

#### Scenario: Agent denied

- GIVEN a user with `ROLE_AGENT`
- WHEN `GET /api/reports/export` is called
- THEN the response is 403

### Requirement: Streamed CSV Response Format

The response MUST be `Content-Type: text/csv; charset=utf-8` with `Content-Disposition: attachment; filename="..."`, streamed via `StreamedResponse` without full in-memory buffering, and MUST NOT impose a row cap.

#### Scenario: Headers signal a file download

- WHEN `GET /api/reports/export` succeeds
- THEN `Content-Type` is `text/csv` and `Content-Disposition` is `attachment` with a filename

#### Scenario: Large result set does not exhaust memory

- GIVEN a filtered range matching 50,000+ tickets
- WHEN the export runs
- THEN peak memory usage does not scale linearly with row count (streamed, not buffered)

### Requirement: CSV Column Contract

Each row MUST contain, in this order, only fields already available from `ListTicketsQuery`'s existing joins — no new joins or per-message attribution: `ticket_id`, `contact_name`, `contact_phone` (UNMASKED — access is already ROLE_SUPERVISOR+ gated), `assigned_agent`, `status`, `priority`, `created_at`, `first_response_at`, `resolved_at`, `closed_at`, `first_response_seconds`, `resolution_seconds`, `tags` (`;`-joined), `phone_number_id`. Timestamps MUST be UTC ISO-8601. The header row MUST list these exact column names.

#### Scenario: Row shape matches contract

- GIVEN a resolved ticket with tags `vip` and `urgente`
- WHEN it appears in the export
- THEN its row has all 14 columns in the defined order, `tags` = `vip;urgente`, timestamps in UTC ISO-8601

#### Scenario: Phone numbers are not masked

- GIVEN a ticket linked to a contact with a full phone number
- WHEN the row is exported
- THEN `contact_phone` contains the complete, unmasked number

### Requirement: Frontend Export Trigger

The reports page MUST show an "Exportar CSV" action only when `isAdmin() || isSupervisor()` evaluates true (these are separate, non-overlapping flags in this codebase). Triggering it MUST perform an authenticated request and download the response as a file client-side.

#### Scenario: Button visible for supervisor and admin

- GIVEN a logged-in supervisor
- WHEN the reports page renders
- THEN "Exportar CSV" is visible
- AND the same holds for a logged-in admin

#### Scenario: Button hidden for agent

- GIVEN a logged-in agent (`isAdmin()` false, `isSupervisor()` false)
- WHEN the reports page renders
- THEN "Exportar CSV" is not rendered

#### Scenario: Click triggers authenticated download

- GIVEN the button is visible
- WHEN the user clicks "Exportar CSV" with active filters set
- THEN an authenticated request is sent with those filters
- AND the CSV response is saved as a downloaded file via blob URL

## Out of Scope (explicit)

- Per-message multi-agent attribution and per-agent message counts (blocked by missing `Message.sender_agent_id`).
- Aggregate-KPI export, XLSX, phone masking.
