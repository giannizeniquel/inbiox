# Proposal: Agent Response Delay Metric & Ticket Participation Report

## Intent

Supervisors cannot answer "who worked this ticket, how much, and how fast". Tier 2 #10 added `Message.sender_agent_id`/`sender_type` but nothing reads it. This change closes original requirement **#11** (participants + detail + delay + count per agent) and the missing "demora entre respuestas" half of **#16** (FRT/RT already exist).

## Product decisions (user-confirmed)

| Topic | Decision |
|---|---|
| Scope | FULL req #11, not just the timestamp-diff query |
| Delay | Inbound (client) message → next `sender_type='agent'` message; one sample per inbound message, not only first-of-conversation |
| Auto-replies | `sender_type='automatic'` never stops the clock and is never credited; shown in detail as activity only |

## Decisions this proposal resolves

| Question | Decision | Rationale |
|---|---|---|
| Ticket vs conversation scoping | **Episode partition**: a message belongs to the most recent ticket opened at or before it on that conversation (`[t.created_at, next_ticket.created_at)`, open end = now). No `ticket_id` column added. | Total, gap-free, non-overlapping partition; delay pairs never cross a reopen boundary; avoids an unbackfillable denormalization |
| Pairing algorithm | Raw SQL CTE + window `SUM(is_agent) OVER (PARTITION BY episode ORDER BY created_at, id ROWS UNBOUNDED PRECEDING)`, self-joined to the closing agent row | Single pass; `automatic` rows simply aren't counted, so the clock keeps running. App-level pairing can't aggregate; correlated self-join is O(n²) |
| Portability | Only the seconds diff differs → reuse `getTimeDiffExpression()`/`isPostgres`. MySQL 8 + PG both support CTE/window | Repo convention; first window-function use |
| Clock endpoints | Start = `direction='inbound'` (non-null, historic). Stop = `sender_type='agent'` | `direction='outbound'` can't separate agent from automatic |
| Unanswered inbound | Excluded from averages, reported as `unanswered_inbound_count` | Prevents silent survivorship bias |
| API | `GET /reports/response-delay` (aggregate + per-agent rows); `GET /reports/tickets/{id}/participation` (participants, msg count/agent, delay/agent, automatic count, inbound→reply pair rows) | Mirrors `/reports/frt`, `/reports/agents` |
| Role gating | `ROLE_AGENT` on both (controller default) | Matches FRT/RT/agents; no bulk PII export here |
| Frontend | Reports page: one delay card + per-agent table. Inbox ticket sidebar: "Participación" panel for the per-ticket detail | Detail is contextual to a ticket; a standalone page would duplicate the ticket list |
| Legacy NULL | `sender_type IS NULL` rows excluded from attribution and denominators; API returns `data_available_from` + `partial_data`; UI shows "sin datos de atribución (desde 12/09/2026)" instead of 0 | Never impute; "0 agentes" would read as a false factual claim |

## Scope

### In Scope
- `ReportingRepository::getResponseDelayMetrics()` + `getTicketParticipation()` (dual-dialect raw SQL)
- `ReportingService` + 2 `ReportingController` routes with `partial_data` envelope
- Index on `messages (conversation_id, created_at)`
- Reports card + per-agent table; inbox ticket participation panel

### Out of Scope
- CSV export (detail is bounded per ticket; streaming pays off only for unbounded sets) → follow-up, reuse `ExportTicketsCsvQuery` pattern
- Realtime/Mercure live refresh; SLA thresholds, alerting, percentiles
- Business-hours-aware delay (overnight gaps inflate raw elapsed time)
- Backfill of legacy `sender_type`; `Message.ticket_id` column

## Capabilities

### New Capabilities
- `response-delay-metrics`: inbound→agent reply latency, aggregate and per agent
- `ticket-agent-participation`: per-ticket participants, per-agent counts and delays, automatic activity

### Modified Capabilities
- None

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `apps/api/src/Repository/ReportingRepository.php` | Modified | 2 window-function queries |
| `apps/api/src/Service/ReportingService.php` | Modified | 2 methods |
| `apps/api/src/Controller/ReportingController.php` | Modified | 2 `ROLE_AGENT` routes |
| `apps/api/migrations/` | New | Index only |
| `apps/web/.../features/reports/` | Modified | Card + per-agent table |
| `apps/web/.../features/inbox/` | Modified | Participation panel |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Window-function cost on large `messages` | Med | Composite index; mandatory bounded date range |
| MySQL/PG SQL divergence (first window use) | Med | Single diff-expression branch; test both in design/apply |
| Burst inflation: 3 inbound + 1 reply = 3 samples | Med | Per user decision; expose sample count so averages are readable |
| Overnight/weekend gaps inflate delay | High | Documented as raw elapsed; business-hours variant is a named follow-up |
| Exceeds 400-line PR budget | High | Chain: PR1 backend + endpoints, PR2 frontend surfaces |

## Rollback Plan

Revert the feature commits; drop the added index. Additive only — no writes, no schema change to existing columns, no data migration, so rollback cannot lose data.

## Dependencies

- Tier 2 #10 `message-agent-attribution` (d354018, merged)
- MySQL 8.0 / PostgreSQL window-function support

## Success Criteria

- [ ] Req #11 answerable: participants, per-agent message count, per-agent delay, per-ticket detail
- [ ] Req #16 "demora entre respuestas por agente" available on the reports page
- [ ] `automatic` messages never close a delay sample and are never credited to an agent
- [ ] Delay samples never cross a ticket-episode boundary on a reused conversation
- [ ] Pre-cutoff ranges render as "sin datos", never as zero
- [ ] Identical results on MySQL and PostgreSQL

## Proposal question round

Assumptions needing user review (non-blocking, defaults applied):
1. Burst inflation — 3 client messages then 1 reply yields 3 samples (literal reading of the confirmed rule). Prefer first-of-burst instead?
2. `ROLE_AGENT` means every agent sees peers' delay numbers. Should per-ticket participation stay agent-visible but the cross-agent aggregate be supervisor-gated?
3. Per-ticket detail lives in the inbox sidebar, not a standalone report page. Confirm?
4. CSV export deferred. Is exporting per-agent delay a first-slice need?
