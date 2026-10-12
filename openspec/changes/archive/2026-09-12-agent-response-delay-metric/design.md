# Design: Agent Response Delay Metric & Ticket Participation Report

## Technical Approach

Two raw-SQL CTE queries in `ReportingRepository`, thin pass-throughs in `ReportingService`, two `ROLE_AGENT` routes in `ReportingController` registered in `config/routes.yaml`. Frontend: one new reports card (aggregate + per-agent table) and one collapsible "Participación" panel inside the inbox conversation container.

**Correction applied (post-proposal, user-confirmed)**: sampling is **one delay sample per burst of consecutive unanswered inbound messages**, not per inbound message. The proposal's `SUM(is_agent) OVER (...)` running count is kept — but it is repurposed from a *pairing key for every inbound row* into a **burst key**: all inbound rows sharing `(ticket_id, reply_seq)` are one burst, and only `ROW_NUMBER() = 1` inside that group produces a sample.

## Architecture Decisions

| Decision | Choice | Rejected | Rationale |
|---|---|---|---|
| Burst collapse | `reply_seq = SUM(is_agent) OVER (PARTITION BY ticket_id ORDER BY created_at,id ROWS UNBOUNDED PRECEDING)`; burst = `(ticket_id, reply_seq)`; sample = `ROW_NUMBER()=1` among inbound rows of that group | `LAG(direction)` "previous row was not inbound" | `LAG` breaks a burst on an interleaved `automatic` row. `reply_seq` only advances on `sender_type='agent'`, so `automatic` neither splits the burst nor stops the clock — Tier 2 #10 semantics fall out for free. Also yields `burst_size` at no cost. |
| Pairing | Equi-join `agent_replies.reply_seq = burst.reply_seq + 1` (LEFT JOIN) | Correlated `MIN(created_at) WHERE created_at > inbound` subquery | Exactly one agent row exists per `reply_seq` value ≥ 1 per ticket, so the closer is a plain hash join, not O(n²). `LEFT` makes unanswered bursts fall out as `NULL`. |
| Range semantics | Filter on `tickets.created_at ∈ [from,to)` (the episode), not message time | filter on `messages.created_at` | Matches `getFrtMetrics`/`getRtMetrics`/`getAgentPerformance`. A burst is never half-in/half-out of the range. |
| Legacy NULL | Compute an attribution cutoff and drop every message before it | `WHERE sender_type IS NOT NULL` alone | Pre-cutoff outbound rows have `is_agent=0`, so every pre-cutoff burst would look **unanswered** and poison `unanswered_inbound_count`. The cutoff is also the `data_available_from` value. |
| Aggregate rollup | SQL returns per-responder groups with `sample_count`/`sum`/`min`/`max`; PHP does the weighted rollup | second full CTE execution for the aggregate row | Avoids duplicating a 40-line CTE; weighted `SUM(sum)/SUM(count)` is exact (avg-of-avg is not). Matches the PHP-side merge in `getVolumeOverTime`. |
| Inbox surface | Collapsible panel in `conversation.component.html`, toggled from `conversation-header` | third grid column in `inbox-page` | **There is no inbox sidebar today** — `inbox-page.component.html` is a 2-child flex `main`. A third column means reworking the responsive layout; an in-flow collapsible is the lowest-risk insertion point. |
| Shared FE types | `core/dto/response-delay.dto.ts`, re-exported by `features/reports/models/reports.models.ts` | put them in `features/reports/models/` | Inbox importing from `features/reports/` is the exact layer violation `core/dto/reports.params.ts` was created to fix. |

## SQL — `getResponseDelayMetrics()`

Dialect-varying part is **only** `{TIMEDIFF}` = `getTimeDiffExpression('b.inbound_at','r.reply_at')`. CTE, `LEAD`, `ROW_NUMBER`, and `SUM() OVER (... ROWS UNBOUNDED PRECEDING)` are byte-identical on MySQL 8.0 and PostgreSQL — **verified portable, no second query body**.

```sql
WITH cutoff AS (
  SELECT MIN(c) AS data_available_from FROM (
      (SELECT created_at AS c FROM messages WHERE sender_type = 'agent'     ORDER BY created_at LIMIT 1)
    UNION ALL
      (SELECT created_at AS c FROM messages WHERE sender_type = 'automatic' ORDER BY created_at LIMIT 1)
    UNION ALL
      (SELECT created_at AS c FROM messages WHERE sender_type = 'contact'   ORDER BY created_at LIMIT 1)
  ) probe
),
scoped_convs AS (
  SELECT DISTINCT t.conversation_id FROM tickets t
  WHERE t.conversation_id IS NOT NULL
    AND t.created_at >= :from AND t.created_at < :to
    /* AND t.phone_number_id = :phoneNumberId */
),
episodes AS (                       -- LEAD over ALL tickets of a scoped conversation
  SELECT t.id AS ticket_id, t.conversation_id, t.created_at AS episode_start,
         LEAD(t.created_at) OVER (PARTITION BY t.conversation_id
                                  ORDER BY t.created_at, t.id) AS episode_end
  FROM tickets t
  JOIN scoped_convs sc ON sc.conversation_id = t.conversation_id
),
msgs AS (
  SELECT e.ticket_id, m.id, m.created_at, m.direction, m.sender_type, m.sender_agent_id
  FROM episodes e
  JOIN messages m ON m.conversation_id = e.conversation_id
                 AND m.created_at >= e.episode_start
                 AND (e.episode_end IS NULL OR m.created_at < e.episode_end)
  CROSS JOIN cutoff cf
  WHERE e.episode_start >= :from AND e.episode_start < :to
    AND m.sender_type IS NOT NULL
    AND m.created_at >= cf.data_available_from
),
seq AS (
  SELECT s.*,
         SUM(CASE WHEN s.sender_type = 'agent' THEN 1 ELSE 0 END)
           OVER (PARTITION BY s.ticket_id ORDER BY s.created_at, s.id
                 ROWS UNBOUNDED PRECEDING) AS reply_seq
  FROM msgs s
),
bursts AS (                         -- ONE row per burst: the first unanswered inbound
  SELECT ticket_id, reply_seq, created_at AS inbound_at, burst_size
  FROM (
    SELECT q.ticket_id, q.reply_seq, q.created_at,
           ROW_NUMBER() OVER (PARTITION BY q.ticket_id, q.reply_seq
                              ORDER BY q.created_at, q.id) AS rn,
           COUNT(*)     OVER (PARTITION BY q.ticket_id, q.reply_seq) AS burst_size
    FROM seq q WHERE q.direction = 'inbound'
  ) ranked
  WHERE rn = 1
),
agent_replies AS (
  SELECT ticket_id, reply_seq, created_at AS reply_at, sender_agent_id
  FROM seq WHERE sender_type = 'agent'
)
SELECT r.sender_agent_id AS agent_id, a.name AS agent_name,
       COUNT(r.reply_at)         AS sample_count,
       SUM({TIMEDIFF})           AS sum_delay,
       MIN({TIMEDIFF})           AS min_delay,
       MAX({TIMEDIFF})           AS max_delay,
       SUM(CASE WHEN r.reply_at IS NULL THEN 1 ELSE 0 END) AS unanswered_count
FROM bursts b
LEFT JOIN agent_replies r ON r.ticket_id = b.ticket_id
                         AND r.reply_seq = b.reply_seq + 1
LEFT JOIN agents a ON a.id = r.sender_agent_id
GROUP BY r.sender_agent_id, a.name
ORDER BY r.sender_agent_id
```

`data_available_from` is fetched by a second one-line query (`SELECT data_available_from FROM cutoff`-equivalent, i.e. the `cutoff` body standalone) so the PHP layer can set `partial_data = cutoff > $from`.

Worked trace — `I1,I2,A1,I3,AU1,I4,A2,I5` → `reply_seq = 0,0,1,1,1,1,2,2`. Bursts `(0)=[I1,I2]→A1`, `(1)=[I3,I4]→A2`, `(2)=[I5]→NULL`. Three inbound-runs, three samples, `AU1` neither split burst 1 nor stopped its clock, `I5` counts as unanswered. Correct.

## SQL — `getTicketParticipation(int $ticketId)`

Same `seq`/`bursts`/`agent_replies` bodies, but `episodes` is replaced by a single-row lookup (no `LEAD` over the table), and the `:from`/`:to` predicates are dropped:

```sql
WITH ep AS (
  SELECT t.id AS ticket_id, t.conversation_id, t.created_at AS episode_start,
         (SELECT MIN(t2.created_at) FROM tickets t2
           WHERE t2.conversation_id = t.conversation_id
             AND (t2.created_at > t.created_at
                  OR (t2.created_at = t.created_at AND t2.id > t.id))) AS episode_end
  FROM tickets t WHERE t.id = :ticketId
)
```

Same `(created_at, id)` lexicographic tie-break as the `LEAD` version, so both queries agree on episode bounds. Two result sets: (1) the pair rows (`SELECT b.inbound_at, b.burst_size, r.reply_at, {TIMEDIFF}, r.sender_agent_id, a.name`, no `GROUP BY`); (2) a message roll-up `SELECT sender_type, sender_agent_id, COUNT(*), MIN(created_at), MAX(created_at) FROM msgs_including_nulls GROUP BY sender_type, sender_agent_id` (this one keeps `sender_type IS NULL` rows so the UI can report `unattributed_count`). PHP assembles `participants` from (2) and folds per-agent delay from (1).

## Method Signatures

`apps/api/src/Repository/ReportingRepository.php` (append after `getReopened`, same `$conn->fetchAllAssociative` + `$this->getTimeDiffExpression(...)` style):

```php
public function getAttributionCutoff(): ?\DateTimeImmutable;
public function getResponseDelayMetrics(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array;
public function getTicketParticipation(int $ticketId): ?array;   // null => ticket not found
```

`apps/api/src/Application/Service/ReportingService.php` (append before `validateDateRange`; same pass-through + `validateDateRange` pattern, plus the `partial_data` envelope assembly):

```php
public function getResponseDelay(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array;
public function getTicketParticipation(int $ticketId): ?array;
```

`apps/api/src/Controller/ReportingController.php`: `responseDelay(Request)` and `ticketParticipation(Request, int $id)`, both using the existing `parseDateTime` / `getScopePhoneNumberId` helpers and the identical `InvalidArgumentException → 400` / `Exception → 500 + referenceId` blocks. `#[IsGranted('ROLE_AGENT')]` is inherited class-level — **no** method-level attribute (only `export` overrides). `ticketParticipation` returns 404 when the service returns `null`.

## Routes — `apps/api/config/routes.yaml` (authoritative; `#[Route]` attributes here are dead code)

```yaml
app_report_response_delay:
    path: /api/reports/response-delay
    methods: [GET]
    controller: App\Controller\ReportingController::responseDelay

app_report_ticket_participation:
    path: /api/reports/tickets/{id}/participation
    methods: [GET]
    controller: App\Controller\ReportingController::ticketParticipation
```

Append after `app_report_export`. No conflict with `app_inbox_ticket_detail` (`/api/tickets/{id}`) — different prefix.

## Response Shapes

`GET /api/reports/response-delay?from=…&to=…`

```jsonc
{
  "avg_delay_seconds": 245.3, "avg_delay_minutes": 4.09,
  "min_delay_seconds": 12, "max_delay_seconds": 3600,
  "sample_count": 128, "unanswered_inbound_count": 7,
  "data_available_from": "2026-09-12T00:00:00Z", "partial_data": true,
  "agents": [{ "agent_id": 3, "agent_name": "Ana", "sample_count": 40,
               "avg_delay_seconds": 180.2, "avg_delay_minutes": 3.0,
               "min_delay_seconds": 10, "max_delay_seconds": 900 }]
}
```

`GET /api/reports/tickets/{id}/participation`

```jsonc
{
  "ticket_id": 42, "episode_start": "…", "episode_end": null,
  "data_available_from": "…", "partial_data": true,
  "message_count": 31, "inbound_count": 14, "automatic_count": 3,
  "unattributed_count": 0, "unanswered_inbound_count": 1,
  "sample_count": 5, "avg_delay_seconds": 210.5,
  "participants": [{ "agent_id": 3, "agent_name": "Ana", "message_count": 9,
                     "sample_count": 3, "avg_delay_seconds": 150.0,
                     "min_delay_seconds": 20, "max_delay_seconds": 400,
                     "first_message_at": "…", "last_message_at": "…" }],
  "pairs": [{ "inbound_at": "…", "burst_size": 3, "reply_at": "…",
              "delay_seconds": 120, "agent_id": 3, "agent_name": "Ana" },
            { "inbound_at": "…", "burst_size": 1, "reply_at": null,
              "delay_seconds": null, "agent_id": null, "agent_name": null }]
}
```

**Never-zero rule**: when `sample_count === 0`, every `*_delay_*` field is `null`, never `0`. `data_available_from` is `null` only when the whole `messages` table is unattributed.

## Frontend

| File | Action | Description |
|---|---|---|
| `apps/web/src/app/core/dto/response-delay.dto.ts` | Create | `ResponseDelayReport`, `ResponseDelayAgentEntry`, `TicketParticipation`, `ParticipationParticipant`, `ParticipationPair` — shared by both features, `core/dto/` avoids the inbox→reports layer violation |
| `apps/web/src/app/features/reports/models/reports.models.ts` | Modify | `export * from '../../../core/dto/response-delay.dto'` (same pattern as `reports.params`) |
| `apps/web/src/app/core/api/reports-api.service.ts` | Modify | `getResponseDelay(params?)` via `buildParams()`; `getTicketParticipation(ticketId)` |
| `apps/web/src/app/core/application/reports/get-reports-dashboard.use-case.ts` | Modify | Add `responseDelay` to the **Group B** `forkJoin` (3→4 calls, at the documented budget); `catchError(() => of(null))` — **not** a zeroed object; add `responseDelay: ResponseDelayReport \| null` to `ReportsDashboardData` |
| `apps/web/src/app/core/application/reports/get-ticket-participation.use-case.ts` | Create | Standalone use case + `TicketParticipationFetchFailed`, `extractApiError`, mirroring `export-tickets-csv.use-case.ts` |
| `apps/web/src/app/core/application/reports/index.ts` | Modify | Export the new use case + types |
| `apps/web/src/app/features/reports/components/response-delay-card/response-delay-card.component.ts` | Create | Standalone, `OnPush`, inline template/styles, `@use '.../kpi-card'`, `kpi-card--wide` (span 2). `@Input({required:true}) data: ResponseDelayReport \| null`, `@Input() loading`. Mirrors `reopened-card`. Renders aggregate + per-agent table; `partial_data` → "sin datos de atribución (desde dd/MM/yyyy)"; `sample_count===0` → `kpi-card__empty`, never `0` |
| `apps/web/src/app/features/reports/pages/reports-page/reports-page.component.{ts,html}` | Modify | `responseDelayLoading` + `responseDelayData = signal<ResponseDelayReport \| null>(null)`; wire into `loadAllData()` Group B; add import + `<app-response-delay-card>` next to the FRT/RT cards |
| `apps/web/src/app/features/inbox/components/ticket-participation/ticket-participation.component.ts` | Create | Standalone, `OnPush`, signal-based. `ticketId = input.required<number>()`; injects `GetTicketParticipationUseCase` (not the API service — layer rule); `effect()` re-fetches on `ticketId` change; `data`/`loading`/`error` signals |
| `apps/web/src/app/features/inbox/components/conversation-header/conversation-header.component.{ts,html}` | Modify | `participationOpen = input<boolean>(false)`, `toggleParticipation = output<void>()`, a "Participación" button next to RESOLVER with `[attr.aria-expanded]` |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.{ts,html}` | Modify | `participationOpen = signal(false)`, `onToggleParticipation()`; render `<app-ticket-participation [ticketId]="activeTicket()!.ticket_id" />` between the header block and the warning banners, gated on `@if (participationOpen())` so the fetch is lazy |

## Data Flow

    reports-page ─→ GetReportsDashboardUseCase ─(Group B forkJoin)→ ReportsApiService
                                                                        │
    conversation ──→ GetTicketParticipationUseCase ────────────────────→┤
                                                                        ↓
                              routes.yaml → ReportingController → ReportingService
                                                                        ↓
                                          ReportingRepository (cutoff → episodes →
                                          msgs → seq → bursts ⋈ agent_replies)

## Migration / Indexes

`messages (conversation_id, created_at, id)` — **already exists** as `idx_messages_conversation_created_id` (`Version20250503000002`), and it is exactly the `PARTITION BY conversation_id ORDER BY created_at, id` access path. **The proposal's planned `messages (conversation_id, created_at)` index is redundant — drop that task.**

Two indexes are genuinely missing, both in one migration following the `Version20260912000000` convention (raw `addSql`, matching `ORM\Index` attributes on the entities, reversible `down()`):

| Index | Table | Why |
|---|---|---|
| `idx_messages_sender_type_created_at (sender_type, created_at)` | `messages` | The cutoff probe. Without it each of the 3 `ORDER BY created_at LIMIT 1` branches is a full table scan; with it each is an index seek. |
| `idx_tickets_conversation_created_id (conversation_id, created_at, id)` | `tickets` | `LEAD(...) OVER (PARTITION BY conversation_id ORDER BY created_at, id)` in `episodes`. Existing `IDX_TICKETS_CONVERSATION (conversation_id)` groups but forces a per-partition filesort. |

Additive, index-only, no data migration. Rollback = drop both.

## Testing Strategy

| Layer | What | How |
|---|---|---|
| Integration (PHPUnit, `tests/Integration/`, alongside `ReportingV2Test.php`) | **Burst collapse**: 3 inbound + 1 reply ⇒ `sample_count = 1`, `burst_size = 3`, delay measured from the FIRST inbound | fixture messages, assert on repository output |
| Integration | `automatic` between two inbound rows does not split the burst and does not stop the clock | the `I1,I2,A1,I3,AU1,I4,A2,I5` trace above |
| Integration | Episode boundary: reused conversation with 2 tickets ⇒ no pair crosses `next_ticket.created_at` | two tickets, one conversation |
| Integration | Trailing unanswered burst ⇒ excluded from avg, counted in `unanswered_inbound_count` | ticket left open |
| Integration | Pre-cutoff rows excluded; `partial_data = true`; `sample_count = 0` ⇒ all delay fields `null` | `sender_type IS NULL` fixtures |
| Integration | **Dual dialect**: the same assertions run against MySQL 8.0 and PostgreSQL | existing test DB setup; this is the repo's first window-function use |
| Integration | 404 for an unknown ticket; 403 for unauthenticated | controller-level |
| Unit (Jest/Karma) | `GetTicketParticipationUseCase` maps errors via `extractApiError`; dashboard use case degrades `responseDelay` to `null`, not a zeroed object | mirrors `get-reports-dashboard.use-case.spec.ts` |
| Component | Card renders "sin datos de atribución" on `partial_data`/empty, never `0` | standalone component harness |

## Threat Matrix

N/A — no routing beyond declarative Symfony route entries, no shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. All SQL uses DBAL named parameters; the only interpolated fragments are `getTimeDiffExpression()` output (code-constant, no user input).

## Open Questions

- [ ] `agents.name` is the display source; agents deactivated after replying still appear via `LEFT JOIN agents` — confirm that is desired (current `getAgentPerformance` filters `a.active = 1`; this query intentionally does not, or historical delay rows would lose their attribution).
- [ ] Range semantics are `tickets.created_at ∈ [from,to)` for consistency with FRT/RT. If supervisors expect "replies that happened in this window", the filter must move to `bursts.inbound_at` — flagged, not assumed.
