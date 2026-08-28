# Proposal: WhatsApp 24h Conversation-Window Compliance

## Intent

Meta rejects free-text messages sent more than 24h after the customer's last inbound message; only approved templates are allowed. Inbiox has no notion of this window: nothing tracks the last inbound timestamp, no caller checks it, the composer always allows free text, and stale tickets never close. Today an agent can persist an outbound message and transition the ticket while Meta silently rejects the send — a phantom "sent" message the customer never received. This is a compliance requirement, not a preference. Grounded by `exploration.md`; its four closed decisions are fixed constraints here.

## Scope

### In Scope
- **3.1** `lastInboundMessageAt` on `Ticket` (+ Doctrine migration), written only by `HandleInboundMessageUseCase` (~L296-301, verified single-writer).
- **3.2** Per-caller window check **before each caller's own persist step**: `HandleOutboundMessageUseCase` (inside `executeTransactionWithRetry()`, right after ticket fetch) and `AutoReplyService::doMaybeSend()` — **explicit scope call: `AutoReplyService` IS in scope.** It has zero window awareness today; leaving it out would make it the one caller ignoring the rule Fase 3 imposes on everyone else.
- Shared, dependency-free window predicate (pure `lastInboundMessageAt` + `now` → bool) in the domain layer, invoked at each caller's boundary. Not copy-pasted logic, not centralized in `GraphClient`.
- Optional `GraphClient::sendMessage()` guard as defense-in-depth backstop only — never a substitute (it fires after the persist-before-send transaction commits).
- **3.3** Expose `lastInboundMessageAt` via the ticket REST serializer + frontend `Ticket` model; give `composer.component.ts` its first `input()` for ticket data (wired from `ConversationComponent.activeTicket()`); dynamic, reversible "window expired → use a template" composer state.
- **3.4** Widen `TicketStateMachine::TRANSITIONS` with `new|active|waiting → closed` (Option A — never route through `resolved`, so resolution metrics stay uncontaminated); add `TransitionTicketStatusUseCase::closeDueToWindowExpiry()`, leaving human `close()`'s `RESOLVED`-only guard untouched.
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
5. Command + compose service; timeline query reads `ticket_events`.

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
| `src/Application/Query/GetContactTimelineQuery.php` | Modified | `UNION ALL` branch over `ticket_events` |
| `src/Command/` + `docker-compose.yml` | New | Expiry-closing command + loop service |
| Ticket serializer / `ticket.model.ts` | Modified | Surface `lastInboundMessageAt` |
| `features/inbox/components/composer/` | Modified | First `input()`; dynamic window-expired state |

## Risks

| Risk | Likelihood | Mitigation |
|------|:---:|------------|
| Mass-closing legitimate tickets on first run of the command | Med | Nullable field is empty for pre-existing tickets — treat `null` as "do not auto-close"; dry-run flag |
| A future caller forgets the per-caller check | Med | Optional `GraphClient` backstop + a test asserting each caller guards before persist |
| Widened FSM lets a future caller close an unresolved ticket via the existing endpoint | Low | Human `close()` keeps its `RESOLVED`-only guard; only `closeDueToWindowExpiry()` uses the new transitions |
| Clock skew / boundary sends rejected by Meta anyway | Low | Apply a safety margin below 24h; keep existing `132000` → 422 mapping as the last line of defense |
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
- [ ] Command closes expired tickets directly from `new`/`active`/`waiting`, never via `resolved`.
- [ ] Each such close writes a `ticket_events` row distinguishable from a human close, visible in the contact timeline.
- [ ] `POST /tickets/{id}/close` still rejects non-`resolved` tickets.
- [ ] PHPUnit failures at or below 58; `ng build --configuration production` exits 0.
