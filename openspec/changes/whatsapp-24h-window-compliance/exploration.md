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

## Unrelated debt noticed in passing (not in scope for this change)

- `StatusActionsComponent` is dead code (zero template usages) and, independently, writes to `TicketStore` directly after an HTTP response — the same store-sync anti-pattern this session's Fase 2 work deliberately avoided for the resolve button. Worth a cleanup pass on its own, separate from this change.
