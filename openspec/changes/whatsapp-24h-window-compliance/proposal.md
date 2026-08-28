# Proposal: WhatsApp 24h Conversation-Window Compliance

## Intent

Meta rejects free-text messages sent more than 24h after the customer's last inbound message; only approved templates are allowed. Inbiox has no notion of this window: nothing tracks the last inbound timestamp, no caller checks it, the composer always allows free text, and stale tickets never close. Today an agent can persist an outbound message and transition the ticket while Meta silently rejects the send — a phantom "sent" message the customer never received. This is a compliance requirement, not a preference. Grounded by `exploration.md`; its four closed decisions are fixed constraints here.

## Scope

### In Scope
- **3.1** `lastInboundMessageAt` on `Ticket` (+ Doctrine migration), written only by `HandleInboundMessageUseCase` (~L296-301, verified single-writer).
- **3.2** Per-caller window check **before each caller's own persist step**: `HandleOutboundMessageUseCase` (inside `executeTransactionWithRetry()`, right after ticket fetch) and `AutoReplyService::doMaybeSend()` — **explicit scope call: `AutoReplyService` IS in scope.** It has zero window awareness today; leaving it out would make it the one caller ignoring the rule Fase 3 imposes on everyone else.
- Shared, dependency-free window predicate (pure `lastInboundMessageAt` + `now` → bool) in the domain layer, invoked at each caller's boundary. Not copy-pasted logic, not centralized in `GraphClient`.
- Optional `GraphClient::sendMessage()` guard as defense-in-depth backstop only — never a substitute (it fires after the persist-before-send transaction commits).
- **3.3** Expose `lastInboundMessageAt` via the ticket REST serializer + frontend `Ticket` model; give `composer.component.ts` its first `input()` for ticket data (wired from `ConversationComponent.activeTicket()`); when the window is expired, disable free-text input and show an explanatory warning banner (`class="warning"`) in its place — plain text, no template-selection affordance (templates aren't built yet). Dynamic and reversible: flips back on when a new inbound message arrives.
- **3.4** Widen `TicketStateMachine::TRANSITIONS` with `new|active|waiting → closed` (Option A — never route through `resolved`, so resolution metrics stay uncontaminated); add `TransitionTicketStatusUseCase::closeDueToWindowExpiry()`, leaving human `close()`'s `RESOLVED`-only guard untouched. The Console Command (3.5) closes **every** expired ticket unconditionally — Meta's rule is time-based, not usage-based, so a `new`/never-worked ticket is closed exactly like any other.
- **System-triggered reopen (new requirement, confirmed 2026-08-28)**: a customer's new inbound message on a ticket closed via `closeDueToWindowExpiry()` reopens **that same ticket** — it must NOT fall into the "no open ticket, create new" path. No human action can ever reopen a window-expiry-closed ticket; this is exclusively a side effect of the customer messaging again.
  - Verified today: `TicketRepository::findNotClosedByContact()` (`WHERE status IN (NEW, ACTIVE, WAITING, RESOLVED)`) excludes ANY `closed` ticket, so `HandleInboundMessageUseCase` (~L262-274) currently creates a brand-new `Ticket` whenever the existing one is `closed` — regardless of why it was closed. This must become reason-aware: only a ticket whose most recent `ticket_events` row is `type = 'window_expired_closed'` is eligible for reuse; a ticket closed some other way (a future human close, once Fase 1 ships) must keep creating a new ticket as today.
  - `TicketStateMachine`: `closed` is documented as strictly terminal today ("closed is terminal — no transitions allowed"). This change needs exactly one narrow exit, symmetric with how `closeDueToWindowExpiry()` bypasses the shared `TRANSITIONS` table with its own hardcoded guard (mirroring `close()`'s own independent `RESOLVED`-only check) rather than adding a table entry: a naive `CLOSED → *` entry in the shared table would also be reachable through the existing generic `POST /tickets/{id}/transition` endpoint, letting a human (or the dead `StatusActionsComponent` if it's ever wired up) reopen a window-expiry-closed ticket at will — exactly what's now explicitly disallowed. **Open technical question for `sdd-design`**: a dedicated method (e.g. `reopenDueToInboundMessage()`) with its own independent guard, called only from `HandleInboundMessageUseCase`'s ticket-resolution step, never touching the shared FSM table — analogous to how `closeDueToWindowExpiry()` and `close()` already coexist without colliding.
- **3.5** New Console Command + docker-compose service following the `OutboxPublishCommand` / `publisher` `while true; sleep N` precedent (15-30 min interval). Not Symfony Scheduler — zero precedent in this repo.
- Closure audit: reuse `TicketEvent`/`ticket_events` with a distinguishing type (e.g. `window_expired_closed`) distinct from a human close; add a `UNION ALL` branch to `GetContactTimelineQuery`. No new table.

### Out of Scope
- Template sending/selection UI (the composer only redirects the agent toward templates).
- Human-close reason plumbing and a `ticket_events.reason` column (Fase 1).
- `StatusActionsComponent` dead code and its direct-store-write anti-pattern — flagged in exploration, **do not touch**.
- Reopening the four closed decisions; `AutoCloseRule`'s unrelated `resolved`+24h clock.

## Capabilities

### New Capabilities
- `whatsapp-conversation-window`: inbound-timestamp tracking, per-caller free-text blocking, composer window state, scheduled expiry closing.
- `ticket-closure-audit`: `ticket_events`-sourced closure history surfaced in the contact timeline, distinguishing system from human closes.

### Modified Capabilities
- None. No spec under `openspec/specs/` covers ticket workflow, messaging, or provider behavior.

## Approach

1. Migration + entity field; write it in the single inbound writer.
2. Domain window predicate + a distinguishable exception; invoke per caller before persist.
3. REST field → frontend model → composer `input()`; live reopen reuses the already-firing `MessageAdded{direction: inbound}` event.
4. FSM widening + `closeDueToWindowExpiry()` writing a `ticket_events` row.
5. Command (closes ALL expired tickets, no exemptions) + compose service; timeline query reads `ticket_events`.
6. Reason-aware ticket lookup in `HandleInboundMessageUseCase` + a dedicated system-only reopen path (design to finalize the exact method/table approach).

## Mercure Event Contract

**Unchanged.** No new or modified Mercure payloads. `TicketUpdatedEvent` is NOT extended (its only producer, `AssignmentService`, is unrelated to inbound messages — extending it would require a brand-new emission site). The load-time baseline arrives over REST; the live "window reopened" signal reuses `MessageAdded{direction: inbound}`, which already carries `direction` and `createdAt`. Per `AGENTS.md`, no polling and no local store patching.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/migrations/` | New | Add `tickets.last_inbound_message_at` (nullable) |
| `src/Domain/Ticket/TicketStateMachine.php` | Modified | Allow `new\|active\|waiting → closed` |
| `src/Application/UseCase/HandleInboundMessageUseCase.php` | Modified | Sole writer of the new field |
| `src/Application/UseCase/HandleOutboundMessageUseCase.php` | Modified | Window check before persist |
| `src/Application/Service/AutoReplyService.php` | Modified | Window check before send/persist |
| `src/Application/UseCase/TransitionTicketStatusUseCase.php` | Modified | `closeDueToWindowExpiry()` + `TicketEvent` write |
| `src/Application/UseCase/HandleInboundMessageUseCase.php` | Modified (2nd) | Reason-aware ticket lookup + system-only reopen from `closed` |
| `src/Repository/TicketRepository.php` | Modified | `findNotClosedByContact()` (or a new method) must check the ticket's latest `ticket_events` type, not just `status`, to decide reuse-vs-new |
| `src/Application/Query/GetContactTimelineQuery.php` | Modified | `UNION ALL` branch over `ticket_events` |
| `src/Command/` + `docker-compose.yml` | New | Expiry-closing command (closes ALL expired tickets unconditionally) + loop service |
| Ticket serializer / `ticket.model.ts` | Modified | Surface `lastInboundMessageAt` |
| `features/inbox/components/composer/` | Modified | First `input()`; dynamic window-expired state |

## Risks

| Risk | Likelihood | Mitigation |
|------|:---:|------------|
| Mass-closing on first run of the command | Med | Nullable field is empty for pre-existing tickets — treat `null` as "do not auto-close" for the first run only, not as a standing exemption (confirmed: **every** genuinely expired ticket must close, `new`/never-worked included — Meta's rule is time-based, not usage-based); dry-run flag |
| A future caller forgets the per-caller check | Med | Optional `GraphClient` backstop + a test asserting each caller guards before persist |
| Widened FSM lets a future caller close an unresolved ticket via the existing endpoint | Low | Human `close()` keeps its `RESOLVED`-only guard; only `closeDueToWindowExpiry()` uses the new close transitions |
| Reopen path reachable through the generic `POST /tickets/{id}/transition` endpoint, letting a human reopen a window-expiry-closed ticket (explicitly disallowed) | Med | Dedicated system-only reopen method with its own guard, never a shared-FSM-table entry a human-facing endpoint could reach — same isolation pattern as `closeDueToWindowExpiry()` vs. `close()` |
| Ticket-lookup query becomes reason-aware (checks `ticket_events`, not just `status`) — a bug here silently creates a duplicate ticket instead of reopening, or reopens a ticket closed for a different reason | Med | Explicit test: inbound message on a `window_expired_closed` ticket reuses it; inbound message on any other `closed` ticket still creates new, unchanged from today |
| Clock skew / boundary sends rejected by Meta anyway | Low | Apply a safety margin below 24h (exact value: `sdd-spec` to propose a default, no fixed number required from product); keep existing `132000` → 422 mapping as the last line of defense |
| Composer blocks sends on stale ticket data | Low | Backend check is authoritative; the UI is advisory only |

## Rollback Plan

- **FSM + use case**: pure code revert of `TicketStateMachine::TRANSITIONS` and `closeDueToWindowExpiry()`. Stop/remove the compose loop service first, otherwise it keeps closing tickets. Already-closed tickets are NOT reopened — `ticket_events` rows of type `window_expired_closed` identify them for a manual reopen if needed.
- **Migration**: `down()` drops `tickets.last_inbound_message_at`. Data loss is acceptable — the column is rebuilt naturally by inbound traffic. Revert the serializer field in the same step so the frontend never reads a missing key.
- **Frontend**: revert independently; the composer falls back to always-enabled, matching today's behavior.

## Dependencies

- Migration baseline fixed and verified (`Version20250503000002`, commit `c590982`) — a new migration is safe to add.
- `TicketEvent`/`ticket_events` (`Version20260505022731`) already exists and is already read by `ReportingRepository`.
- Existing `MessageAdded` Mercure payload already carries `direction` + `createdAt`.

## Testing Constraints

Per `openspec/config.yaml`: backend strict TDD (failing test first) baselined against **~58 pre-existing PHPUnit failures** — must not exceed that count. Frontend Karma/Jasmine is unrunnable here (no Chrome/Chromium); `ng build --configuration production` is the enforceable frontend gate.

## Success Criteria

- [ ] `lastInboundMessageAt` set on every inbound message and on no other path.
- [ ] Both `HandleOutboundMessageUseCase` and `AutoReplyService` reject free text outside the window **before** persisting; no phantom message or FSM transition results from a blocked send.
- [ ] Composer reflects window state from ticket load and flips back on when a new inbound message arrives, with no polling and no Mercure payload change.
- [ ] Command closes **every** expired ticket directly from `new`/`active`/`waiting`, never via `resolved`, with no exemption for never-worked tickets.
- [ ] Each such close writes a `ticket_events` row distinguishable from a human close, visible in the contact timeline.
- [ ] A new inbound message on a `window_expired_closed` ticket reopens that same ticket (never creates a new one); an inbound message on any other `closed` ticket still creates a new ticket, unchanged from today.
- [ ] No human-facing endpoint (including the generic `/transition`) can reopen a window-expiry-closed ticket.
- [ ] `POST /tickets/{id}/close` still rejects non-`resolved` tickets.
- [ ] When the window is expired, the composer shows a `class="warning"` explanatory message in place of the text input; reverts automatically on the next inbound message.
- [ ] PHPUnit failures at or below 58; `ng build --configuration production` exits 0.
