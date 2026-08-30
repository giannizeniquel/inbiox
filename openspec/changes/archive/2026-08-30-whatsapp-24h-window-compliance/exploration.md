# Exploration: WhatsApp Meta 24h Conversation-Window Compliance

**Change:** `whatsapp-24h-window-compliance` (Sprint 16 Fase 3)
**Date:** 2026-08-28
**Source:** `docs/04_sprints/sprint_16 (Production Readiness).md`, "Fase 3" section

## Business rule

WhatsApp's 24h window is counted from the last message **sent by the customer**, resets on each new customer message, and outside that window only Meta-approved template messages can be sent — free text is rejected by Meta. This is a regulatory/compliance requirement, not a product preference.

## Current state (verified against code, 2026-08-28)

- `lastInboundMessageAt` / `last_inbound_message_at` does not exist anywhere in `apps/`.
- `TicketStateMachine::TRANSITIONS` (`apps/api/src/Domain/Ticket/TicketStateMachine.php`): `new→active`, `active→{waiting,resolved}`, `waiting→{active,resolved}`, `resolved→{waiting,closed}`. No direct-to-`closed` from `new`/`active`/`waiting`.
- No Command or scheduled task exists for window-expiry closing.
- `Conversation.lastMessageAt` is overwritten by both inbound and outbound messages — not a valid substitute for "last message from the customer".
- `AutoCloseRule` only closes `resolved` tickets after 24h of `ticket.updatedAt`, fires only manually via API. Unrelated clock, unrelated trigger — do not confuse with what this change needs.
- The migration baseline blocker that previously prevented a clean `doctrine:migrations:migrate` is fixed and verified (`Version20250503000002`, commit `c590982`). A new migration for `lastInboundMessageAt` is safe to add.

The sprint doc's 3.1–3.5 breakdown is directionally correct. Each item below states what holds, what doesn't, and the exact insertion point.

## 3.1 — Dedicated `lastInboundMessageAt` field

**Holds as written.** Single-writer point confirmed: `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` (~line 296-301), alongside the existing conversation/ticket timestamp updates. `AutoReplyService` (`apps/api/src/Application/Service/AutoReplyService.php`) confirmed clean — never touches ticket timestamps, so nothing else needs to change to keep this field inbound-only.

## 3.2 — Backend block of free text outside the window

**The sprint doc's phrasing is accurate but incomplete, and the gap is safety-critical.**

Verified call chain in `apps/api/src/Controller/ProviderProxyController.php::send()`:
1. Line 98: calls `HandleOutboundMessageUseCase::processOutboundMessage()`.
2. Inside `executeTransactionWithRetry()` (line 139), everything runs inside `$this->em->getConnection()->transactional(...)`: the ticket is fetched (line 141), the outbound `Message` is persisted (`$this->em->persist($msg)`, ~line 197), and the FSM transition executes — **all committed** when this transaction closes.
3. Only *after* that transaction has committed does the controller call `$this->graphClient->sendMessage(...)` (line 141 of the controller).

**Consequence:** a window check placed only right before the `GraphClient::sendMessage()` call is too late — the outbound `Message` row and the FSM transition are already committed. A rejected send would leave a phantom "sent" message the customer never received, with the ticket already transitioned as if the reply went through.

**Correct insertion point:** inside `HandleOutboundMessageUseCase::executeTransactionWithRetry()`, immediately after the ticket is fetched (`apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` ~line 141), throwing a distinguishable exception the controller catches the same way it already catches `GraphApiException`, before anything is persisted.

**Additional gap found:** `AutoReplyService::doMaybeSend()` calls `GraphClient::sendMessage()` **directly**, bypassing both `ProviderProxyController` and `HandleOutboundMessageUseCase`. It is a third, currently-unguarded caller. Low practical risk today (it only fires immediately after an inbound message, when the window is guaranteed open), but structurally a gap if `AutoReplyService` is ever triggered on a different schedule.

**Open design decision:** put the check in `GraphClient::sendMessage()` itself as a single choke point covering all three callers, vs. duplicating the check per caller (which risks a caller being added later without it, as already happened with `AutoReplyService`).

## 3.3 — Frontend composer reflects window state

**Bigger gap than the sprint doc implies.**

`apps/web/src/app/features/inbox/components/composer/composer.component.ts` currently has **zero `input()` signals** — only two `output()`s. It has no access to ticket data at all today. The parent `ConversationComponent` already exposes `activeTicket()` (`ticketStore.activeTicket`), so wiring the data through is straightforward once the field exists end-to-end.

The inert-button pattern added this session for the composer's emoji/voice buttons (commit `b3d2cb0` — static `disabled` + explanatory `title`) does **not** fit as-is: that was a permanent, non-reactive disable for a feature that was never built. Window-expiry is dynamic, reversible (the customer can message again and reopen the window), and blocks the *primary* action rather than a decorative one — it needs a state that can flip back on, not a static disable.

**Open design decision:** how the frontend learns the window state changed. The Mercure `TicketUpdatedEvent` payload carries only status/assignee today, not timestamps. Two options, both must stay event-driven per this project's convention (`AGENTS.md`: "event-driven realtime, not state sync" — no local polling):
- Derive "window just reopened" locally from the already-received `MessageCreatedEvent{direction: inbound}`, without a new Mercure field.
- Extend the `TicketUpdatedEvent` payload with the new timestamp.

## 3.4 — New FSM transitions allowing direct-to-`closed`

**Widening `TicketStateMachine::TRANSITIONS` alone is not sufficient — this is the most consequential finding of the exploration.**

`TransitionTicketStatusUseCase::close()` (`apps/api/src/Application/UseCase/TransitionTicketStatusUseCase.php`, line 140) has its own hardcoded guard, entirely independent of the FSM transition table:

```php
if ($ticket['status'] !== TicketStatus::RESOLVED->value) {
    throw new \InvalidArgumentException('Only resolved tickets can be closed');
}
```

This is the exact method `TicketWorkflowController::close()` calls for `POST /tickets/{id}/close` (verified, line 73). Widening the FSM table without also touching this guard would do nothing — `close()` would still reject non-`resolved` tickets. Widening *this guard* naively, on the other hand, would let this same endpoint close a brand-new or unresolved ticket for **any** caller, not just the window-expiry job.

**Correction to an earlier exploration finding:** the initial pass claimed this endpoint was "the exact endpoint Fase 2 just wired to the frontend 'Cerrar' button." **That is not accurate, verified against the actual frontend code.** Fase 2 (commit `b3d2cb0`, this session) wired only the **resolve** button (`onResolveTicket()` → `WorkflowApiService.resolve()` → `POST /tickets/{id}/resolve`). `WorkflowApiService.close()` exists (added in the same commit, for API completeness) but is called by **no component**.

The only frontend code that references ticket closing today is `StatusActionsComponent` (`apps/web/src/app/features/inbox/components/status-actions/status-actions.component.ts`), which is **dead code** — `rg` confirms `<app-status-actions>` appears in zero templates. It also does not call `close()`; it calls the generic `WorkflowApiService.transition()` to move `Resolved → Closed`, and — a separate, pre-existing issue — writes directly to `TicketStore` after the HTTP response (`ticketStore.addOrUpdateTicket(updatedTicket)`), which bypasses the event-driven Mercure pipeline the same way this session's Fase 2 work deliberately avoided for the resolve button. Noted as unrelated debt below; not in scope here.

**Net effect on the actual risk:** there is no live UI path today that a naive `close()` widening would silently break. The risk is forward-looking (any future caller of the existing `POST /tickets/{id}/close` endpoint, plus the dead `StatusActionsComponent` if it were ever wired up) rather than an active collision. The guard still needs a dedicated path so a human "close" action and a system window-expiry close can't be confused — a `closeDueToWindowExpiry()` method (or equivalent explicit caller-context gating) is still the right design, just for correctness/auditability reasons rather than to protect an already-shipped button.

## 3.5 — Scheduled job for window-expiry closing

**No Symfony Scheduler or crontab precedent exists anywhere in this codebase.** Concrete precedent found instead: `docker-compose.yml`'s `publisher` service —

```
sh -c "while true; do php bin/console app:outbox:publish --status=pending; sleep 2; done"
```

wrapping `apps/api/src/Command/OutboxPublishCommand.php`. This de-risks the "introducing a new pattern" concern: reuse this exact shape — a new Command plus a new compose service with a longer sleep interval (the sprint doc suggests 15-30 min) — rather than introducing Symfony Scheduler as a new dependency for a single job.

## Audit/history gap (confirms the sprint doc's Fase 3-before-Fase 1 sequencing)

No actor/reason plumbing exists anywhere in the closure audit trail: `recordUnassignIfOwner()` hardcodes `'system'`, and `GetContactTimelineQuery` (`apps/api/src/Application/Query/GetContactTimelineQuery.php`) only reads `outbox_events` (generic `ticket.status_changed`) and `assignments` — there is no closure-reason column or event type anywhere.

`AutoCloseRule` (`apps/api/src/Domain/Rule/AutoCloseRule.php`) already emits a `TicketEvent` entity for automated closes, but it is **never read** by the timeline query — an existing, orphaned audit mechanism. This confirms the sprint doc's sequencing decision (Fase 3 before Fase 1) is load-bearing, not just a preference: Fase 3's window-expiry closes need *some* distinguishable audit trail now, and Fase 1 will need the same mechanism for human closes — building it once, here, avoids two audit paths.

## Open decisions for `sdd-propose`

1. **Window-check choke point:** single point inside `GraphClient::sendMessage()` (covers `ProviderProxyController`, `HandleOutboundMessageUseCase`, and `AutoReplyService` uniformly) vs. duplicated checks per caller (more explicit call sites, more places to forget).
2. **`TransitionTicketStatusUseCase` shape:** new dedicated `closeDueToWindowExpiry()` method vs. widened `close()` with explicit caller-context gating (e.g. a reason/actor parameter). No live UI collision today, but the distinction matters for the Fase 1 handoff (human close vs. system close must stay auditably distinct).
3. **Closure audit mechanism:** repair and reuse the orphaned `TicketEvent`/`AutoCloseRule` mechanism (make `GetContactTimelineQuery` read it) vs. design a new event type shared with Fase 1. Repairing the existing one is probably in scope here since Fase 3 needs *a* working closure-audit path regardless.
4. **Frontend window-state propagation:** derive "window reopened" from the existing `MessageCreatedEvent{direction: inbound}` vs. extend the `TicketUpdatedEvent` Mercure payload with the new timestamp. Must stay event-driven either way — no polling.

## Detailed tradeoff analysis (decisions 1, 3, 4) — 2026-08-28

Decision #2 (`closeDueToWindowExpiry()`, a dedicated method separate from human `close()`) is settled — no further analysis needed.

### Decision 1 — Choke point in `GraphClient::sendMessage()` vs. per-caller checks

`GraphClient` (`apps/api/src/Application/Service/GraphClient.php`) is a genuinely thin wrapper today: constructor takes only `accessToken`, `phoneNumberId`, `HttpClientInterface` (L23-27), zero Doctrine/domain imports. `sendMessage()` (L89-121) and `sendTemplate()` (L235-282) are independent method bodies sharing only `normalizePhone()` (phone formatting) — so a check inside `sendMessage()` cannot leak into `sendTemplate()` today, though that would need re-checking if the two methods are ever refactored to share more code.

**The phantom-message question has a definitive, code-verified answer: a `GraphClient`-only choke point does NOT solve it.**

Verified the exact ordering in both callers:
- **`AutoReplyService::doMaybeSend()`** (`apps/api/src/Application/Service/AutoReplyService.php`): calls `GraphClient::sendMessage()` at **L121**, and only constructs + `persist()`s the local `Message` **afterward**, at **L137-145**, using the real WhatsApp message ID (`$wamid`) from the Graph response. Send-before-persist. A guard thrown from inside `GraphClient` would, today, prevent this caller's phantom message — but only as a side effect of this specific ordering, not because of anything the architecture guarantees. If this method is ever refactored to persist-then-send (matching the other flow), the protection silently disappears with no compiler or test signal.
- **`HandleOutboundMessageUseCase`** (already documented above): persists the `Message` and executes the FSM transition inside a transaction that **commits before** `ProviderProxyController::send()` calls `GraphClient::sendMessage()`. A guard inside `GraphClient` fires strictly after that transaction has already closed — no exception thrown from that deep in the call stack can undo it. **This is the flow where the actual phantom-message risk lives, and a `GraphClient`-only check does nothing for it.**

Conclusion: **each caller must check the window before its own persist step, regardless of where any additional check lives.** A `GraphClient`-level check can only ever be a redundant backstop, not the fix.

Other considerations:
- What `GraphClient` would need to accept a timestamp/entity/repository to check the window, none of it free: a raw `DateTimeImmutable` param keeps it dependency-free but embeds a compliance rule in a transport class (and still requires each caller to resolve the value, which is most of the actual work anyway); accepting a `Ticket` entity or injecting a repository is a real layering violation for what the class's own docblock calls a "thin HTTP wrapper."
- `ProviderProxyController` already maps Meta's real error code `132000` to HTTP 422 / `MESSAGE_TOO_OLD` (`mapGraphErrorCode()`, L386-408) — reusing `GraphApiException(..., 132000)` for a proactive check flows through existing catch blocks for free, but is dishonest about the exception's origin (it didn't come from Graph). A distinct exception type is honest but needs new catch wiring, and either way doesn't change the ordering problem above.
- Testability: a check inside `GraphClient` couples window-logic tests to HTTP-client mocking; a small dedicated check (pure function: window open given `lastInboundMessageAt` + `now`) is trivially unit-testable and lets each caller test its own guard-before-persist ordering independently.

| | Choke point in `GraphClient` | Duplicated per-caller check |
|---|---|---|
| Prevents phantom messages | **No** — only accidentally protects `AutoReplyService` (send-before-persist order); does nothing for the persist-before-send flow, which is the real risk | Yes, by construction |
| Layering | Forces a thin HTTP wrapper to accept a business timestamp/entity it doesn't need | Keeps `GraphClient` untouched |
| Template safety | Safe today, fragile if `sendMessage`/`sendTemplate` share code later | N/A |
| Exception mapping | Can reuse existing `132000`/`MESSAGE_TOO_OLD` contract, at the cost of honesty about origin | Each caller controls its own error shape |
| Testability | Coupled to HTTP-client mocking | Pure/isolated |
| Forgotten-caller risk | Lower (one place), but false sense of completeness | Higher, as already happened once with `AutoReplyService` |
| Defense-in-depth | Can be added *in addition to* per-caller checks | N/A |

### Decision 3 — Repair `TicketEvent`/`AutoCloseRule` audit path vs. build new

`TicketEvent` (`apps/api/src/Domain/Event/TicketEvent.php`, table `ticket_events` from `Version20260505022731.php` L27): fields `id`, `ticketId`, `type` (varchar 50), `source` (varchar 20, default `'system'`), `createdAt`. **Not narrowly shaped for `AutoCloseRule`** — already reused by `AutoReopenRule` (`'auto_reopened'`), `AddTagToTicketUseCase`/`RemoveTagFromTicketUseCase` (`'tag_added'`/`'tag_removed'`, with `source` as a real parameter, not hardcoded). It is also already **read** by `ReportingRepository` (L704-710, joined on `type = 'auto_reopened'`) — so "orphaned" is accurate only relative to `GetContactTimelineQuery`, which unions solely `outbox_events` and `assignments` and never touches `ticket_events`.

A new type value (`window_expired_closed`) fits the existing shape with zero schema change. The one real gap: no free-text reason/note column — `source` is a short actor tag, not a reason. If Fase 1 later needs richer human-close reasons beyond who/what triggered it, that's a small additive nullable column, not a redesign.

**Duplicate-timeline-entry risk — checked and refuted.** Read `TransitionTicketStatusUseCase` in full: both `close()` and `resolve()`/`execute()` perform raw SQL `executeStatement()` UPDATEs directly, never touching `EventCollector` or constructing `TicketUpdatedEvent`. Grepped the only construction site of `TicketUpdatedEvent`: `AssignmentService.php` (assignment changes only). **Resolving or closing a ticket today produces zero outbox events at all.** The speculative collision risk noted in the first exploration pass does not apply — there is nothing in `outbox_events` for a new `ticket_events`-sourced timeline entry to collide with. (Separate, adjacent gap worth flagging: the frontend's `TicketStatusChanged` handler in `ticket.store.ts` is wired and ready, but nothing currently fires that event for resolve/close — out of scope here.)

| | Repair (`GetContactTimelineQuery` reads `ticket_events`) | New event type/table |
|---|---|---|
| Schema change | None for the window-expiry case; a `reason` column is a small additive migration if ever needed | New table, migration, DTO, and read path from scratch |
| Existing consumers | `ReportingRepository` already depends on this table/shape — consistent reuse | Two parallel audit mechanisms until Fase 1 unifies them |
| Duplication risk | None — confirmed no outbox event fires for close()/resolve() | N/A, but doesn't need to solve a problem that doesn't exist |
| Fase 1 fit | `source` already supports non-`'system'` actors — incremental extension | Fase 1 would need to migrate off it or maintain two paths |
| Effort | Low — one `UNION ALL` branch + DTO mapping + optional column | Medium/high |

### Decision 4 — Frontend window-state propagation

Confirmed `Message` (frontend, `message.model.ts` L7-30) already carries `direction` and `createdAt`; `MessageAddedPayload` (`realtime-event.model.ts` L44-56) carries both too, and backend `RealtimeEventFactory::buildPayload()` for `MessageAdded` (L69-79) already emits them. So reacting live to an already-open chat requires **zero backend changes** — confirmed.

**But local-only derivation is unsafe at ticket-open time — confirmed, and worse than a scroll edge case.** All three initial-load call sites (`app-shell.component.ts:145`, `ticket-list.component.ts:179`, `inbox-page.component.ts:57`) call `getMessages(ticketId, 20, 0)` — most recent 20 messages, offset 0, every time a ticket is opened. `ConversationStore.prependMessages()` is only used for scroll-up pagination of *older* history, never for the initial fetch. **The moment a ticket is opened**, if the true last inbound message isn't among the most recent 20 (realistic: this codebase sends one message per media attachment via `sendMediaMessage()`, so a burst of agent replies with attachments easily exceeds 20 outbound messages after the last customer message), a local `max(createdAt where direction==='inbound')` over loaded messages either finds nothing (wrongly treats the window as never-open) or returns a stale timestamp (understates elapsed time) — wrong at page load, not just after scrolling.

Extending `TicketUpdatedEvent`'s payload is also a bigger change than it first appears: its only construction site is `AssignmentService.php` (assignment flows) — it is never fired by anything related to inbound messages. Carrying `lastInboundMessageAt` through it would require a **new emission call** at `HandleInboundMessageUseCase`, a currently non-participating call site — not just a field added to an existing payload.

**A third option, not in the original framing**: split the concern. A REST-surfaced baseline (`Ticket.timestamps.last_inbound_message_at`, added to the ticket serializer) gives a correct value at ticket-open time immune to the pagination cap, and the existing `MessageAdded{direction:inbound}` event (already firing, zero changes) handles the *incremental* "window just reopened while the chat is open" signal. These aren't mutually exclusive — the REST field solves the load-time correctness gap that neither pure-local nor Mercure-only options solve.

| | Local-only (`MessageCreatedEvent`) | Extend `TicketUpdatedEvent` | REST baseline + local incremental |
|---|---|---|---|
| Backend change | None | New emission call at `HandleInboundMessageUseCase` (not currently a `TicketUpdatedEvent` producer) + payload field | Ticket serializer field addition |
| Frontend change | Computed signal only | New payload type field + `ticket.store.ts` handling | `Ticket` interface field + one-time read, reuses the local listener |
| Correct at ticket-open | **No** — confirmed unsafe due to the 20-message/offset-0 initial load cap | Yes, once wired to the right trigger | Yes — sourced from the ticket record, immune to the cap |
| Correct while chat stays open | Yes | Yes (redundant machinery) | Yes (same local listener) |
| Effort | Lowest, but incomplete | Medium — easy to underestimate the new emission site | Medium — a genuinely separate REST change on top of no Mercure work |

## Decisions closed — 2026-08-28

All four open decisions are settled. `sdd-propose` should treat these as fixed constraints, not options to re-litigate.

1. **Window-check placement: per-caller, not a `GraphClient`-only choke point.** Each of the three callers (`HandleOutboundMessageUseCase`, `AutoReplyService`, and any future caller) MUST check the window before its own persist step. A guard inside `GraphClient::sendMessage()` MAY additionally be added as a defense-in-depth backstop, but is explicitly not a substitute for the per-caller checks — confirmed it cannot prevent the phantom-message case for the persist-before-send flow (`HandleOutboundMessageUseCase`).
2. **`TransitionTicketStatusUseCase` shape: dedicated `closeDueToWindowExpiry()` method**, separate from the human `close()`. (Decided earlier, restated here for completeness.)
3. **Closure audit: repair and reuse the existing `TicketEvent`/`ticket_events` mechanism.** `GetContactTimelineQuery` gains a `UNION ALL` branch reading `ticket_events`. No new table. A `reason` column may be added later if Fase 1 needs it — not required now.
4. **Frontend window-state: REST baseline + existing live event, not Mercure payload changes.** Add `lastInboundMessageAt` to the `Ticket` REST serializer/response and the frontend `Ticket.timestamps` model, read once at ticket load. For live updates while the chat stays open, reuse the already-firing `MessageAdded{direction: inbound}` event (zero backend changes needed for that half). Do not attempt to derive the value purely from `ConversationStore.messages()` (unsafe per the confirmed 20-message/offset-0 initial load), and do not extend `TicketUpdatedEvent`'s payload (its only producer, `AssignmentService`, is unrelated to inbound messages — would require a new emission call, not a field addition).

## Unrelated debt noticed in passing (not in scope for this change)

- `StatusActionsComponent` is dead code (zero template usages) and, independently, writes to `TicketStore` directly after an HTTP response — the same store-sync anti-pattern this session's Fase 2 work deliberately avoided for the resolve button. Worth a cleanup pass on its own, separate from this change.
