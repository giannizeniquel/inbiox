# Design: WhatsApp 24h Conversation-Window Compliance

Grounded by `exploration.md` (verified call chains) and `proposal.md` (fixed constraints 1-6). This document decides HOW; `specs/` decides WHAT.

## Technical Approach

Five independent seams, one shared domain predicate:

1. **Domain**: `ConversationWindowPolicy` — dependency-free, configurable safety margin, two asymmetric null semantics.
2. **Write path**: each sending caller consults the policy *before its own persist step* (never in `GraphClient`).
3. **Close path**: widened FSM + `closeDueToWindowExpiry()` + a `ticket_events` audit row, driven by a `while true; sleep N` compose loop.
4. **Reopen path**: a reason-aware repository fallback plus a dedicated use-case method with its **own** guard, structurally unreachable from any human endpoint.
5. **Read path**: REST baseline on `Ticket.timestamps`, derived (not patched) in the frontend, live-corrected by the already-firing `MessageAdded` event.

---

## Architecture Decisions

### D1 — Reopen mechanism (the open question)

| Option | Tradeoff | Verdict |
|---|---|---|
| Add `CLOSED → ACTIVE` to `TicketStateMachine::TRANSITIONS` | Reachable by `TransitionTicketStatusUseCase::execute()`, i.e. by `POST /tickets/{id}/transition` and by the dead `StatusActionsComponent` if ever wired — directly violates "no human action can reopen" | **Rejected** |
| Reason-aware widening of `findNotClosedByContact()` | Method name lies; changes an existing method every caller/test depends on; regression surface for the *unchanged* path | **Rejected** |
| **New repository method + dedicated `reopenDueToInboundMessage()` with an independent guard, never touching the shared table** | Two small additive units; existing paths byte-identical; mirrors the already-verified `close()` / `closeDueToWindowExpiry()` coexistence pattern | **Chosen** |

**Why the asymmetry with D2 is deliberate**: closing goes *through* the widened shared table (an agent closing an unresolved ticket via `/transition` is semantically legal and low-harm, and human `close()` keeps its `RESOLVED`-only guard). Reopening does **not**, because the requirement explicitly forbids it existing as a human-reachable capability at all. `closed` therefore stays terminal in `TRANSITIONS` and `isTerminal('closed')` stays `true` — `execute()` keeps throwing `InvalidTransitionException` for every `closed → *` target, unchanged.

### D2 — FSM widening (closing side)

`TicketStateMachine::TRANSITIONS` gains `CLOSED => true` under `NEW`, `ACTIVE`, `WAITING` (Option A — never routed through `resolved`, so `resolved_at` and resolution metrics stay uncontaminated). Update the class docblock. `resolved → closed` already exists.

### D3 — Window predicate placement

`App\Domain\Ticket\ConversationWindowPolicy` — a final, dependency-free service (no Doctrine, no HTTP), autowired with a bound scalar. Rejected: a static helper (margin not injectable/testable), a method on `Ticket` (entity would own a compliance clock), `GraphClient` (proved ineffective for the persist-before-send flow, and a layering violation).

### D4 — No `GraphClient` backstop

The proposal allows one optionally. **Declined.** Exploration proved it cannot prevent the real phantom-message case, `sendMessage()` has 3 call sites whose signature would change, and it would put a compliance rule in a class its own docblock calls a thin HTTP wrapper. Defense-in-depth is delivered instead by a per-caller guard-before-persist test (see Testing) plus the existing `132000 → 422 MESSAGE_TOO_OLD` mapping as last line of defense.

### D5 — Distinguishable exception, not `GraphApiException(132000)`

New `App\Exception\ConversationWindowExpiredException`. Reusing Graph code `132000` would flow through existing catches for free but lies about the exception's origin (it never came from Meta) and would be indistinguishable in logs from a genuine Meta rejection.

### D6 — Timeline type allow-list

The new `UNION ALL` branch reads only `te.type IN ('window_expired_closed','window_reopened')`. Reading every `ticket_events` type would silently add `tag_added`/`tag_removed`/`auto_reopened` to every contact timeline — a behavior change outside this change's scope. Widening later is one string in a list.

### D7 — Frontend derivation, not store patching

Per `openspec/config.yaml` (`no local store patches that duplicate event-driven updates`) the container computes `max(REST baseline, latest inbound in ConversationStore.messages())`. Nothing is written back to any store; the existing `MessageAdded` handler at `conversation.store.ts:67-88` remains the only writer.

### D8 — Null semantics are asymmetric on purpose

| Context | `lastInboundMessageAt === null` | Why |
|---|---|---|
| Backend, sending | **Blocked** (fail closed) | Never send free text we cannot prove is inside the window |
| Backend, expiry-closing | **Not closed** (fail open) | Prevents mass-closing every pre-existing ticket on first run (proposal risk table) |
| Frontend | **Not blocked** (advisory) | The UI must never falsely lock an agent out; the backend is the compliance authority |

---

## Reopen Flow — Sequence Diagram

```
Meta webhook          HandleInboundMessageUseCase        TicketRepository      TransitionTicketStatusUseCase       MySQL
     │                            │                             │                          │                        │
     ├─ inbound msg ─────────────►│                             │                          │                        │
     │              (steps 2-5: contact, conversation,           │                          │                        │
     │               idempotency, persist Message)               │                          │                        │
     │                            │                             │                          │                        │
     │             step 6a        ├─ findNotClosedByContact ────►├─ status IN (new,active, ─────────────────────────►│
     │                            │◄──────── null ──────────────┤  waiting,resolved)       │                        │
     │                            │                             │                          │                        │
     │             step 6b        ├─ findWindowExpiredClosed ───►├─ status='closed' AND     ─────────────────────────►│
     │              (NEW)         │      ByContact              │  (latest ticket_events   │                        │
     │                            │◄─── Ticket #42 | null ──────┤   .type)='window_expired_closed'                  │
     │                            │                             │                          │                        │
     │              ┌─ null ──────┴─► create new Ticket (unchanged from today) ─────────────────────────────────────►│
     │              │                                           │                          │                        │
     │              └─ Ticket #42 ─┤ conversation-id match?      │                          │                        │
     │                            ├─ reopenDueToInboundMessage(42) ────────────────────────►│                        │
     │                            │                             │       SELECT … FOR UPDATE ────────────────────────►│
     │                            │                             │       guard 1: status === 'closed'                 │
     │                            │                             │       guard 2: latest ticket_events.type           │
     │                            │                             │                === 'window_expired_closed'         │
     │                            │                             │       UPDATE status='active', closed_at=NULL ─────►│
     │                            │                             │       INSERT ticket_events('window_reopened') ────►│
     │                            │◄──── {previous:closed, new:active} ──┤                  │                        │
     │                            ├─ em->lock(PESSIMISTIC_WRITE) + em->refresh()  ◄── raw SQL made the entity stale  │
     │                            │                             │                          │                        │
     │             step 7         │ status is 'active' → the waiting→active branch does NOT fire                     │
     │             step 8         ├─ ticket.setLastInboundMessageAt(msg.createdAt); flush ────────────────────────────►│
     │             step 9+        ├─ MessageCreatedEvent{direction: inbound} → outbox → Mercure (unchanged)          │
```

**`TicketWorkflowController` / `POST /tickets/{id}/transition` never appear in this diagram — that is the isolation guarantee.** `reopenDueToInboundMessage()` has exactly one call site.

---

## Interfaces / Contracts

### `App\Domain\Ticket\ConversationWindowPolicy` (new)

```php
final class ConversationWindowPolicy
{
    public const WINDOW_HOURS = 24;

    public function __construct(private readonly int $safetyMarginMinutes = 0) {}

    /** Sending: null => false (fail closed). */
    public function isOpenForSending(?\DateTimeImmutable $lastInboundMessageAt, \DateTimeImmutable $now): bool;

    /** Expiry-closing: null => false (fail open — do not close tickets with no known inbound). */
    public function isExpiredForClosing(?\DateTimeImmutable $lastInboundMessageAt, \DateTimeImmutable $now): bool;

    /** Effective deadline = lastInbound + 24h - safetyMargin. */
    public function expiresAt(\DateTimeImmutable $lastInboundMessageAt): \DateTimeImmutable;
}
```

Margin wiring: `config/services.yaml` bind `$safetyMarginMinutes: '%env(int:CONVERSATION_WINDOW_SAFETY_MARGIN_MINUTES)%'`, default declared in `.env`. **The numeric default is `sdd-spec`'s call** — read it from `specs/` once both artifacts land; this design only fixes the mechanism as configurable, never hardcoded.

### `App\Domain\Event\TicketEventType` (new)

```php
final class TicketEventType
{
    public const WINDOW_EXPIRED_CLOSED = 'window_expired_closed';
    public const WINDOW_REOPENED       = 'window_reopened';
}
```
Existing raw-string types (`auto_closed`, `auto_reopened`, `tag_added`, `tag_removed`) are **not** refactored — out of scope.

### `TicketRepository::findWindowExpiredClosedByContact()` (new)

DQL cannot express a correlated `ORDER BY … LIMIT 1` subquery, so this method uses the connection directly (precedent: `TransitionTicketStatusUseCase`), then rehydrates via `$this->find($id)` so the caller receives a managed entity it can `lock()`.

```sql
SELECT t.id
FROM tickets t
WHERE t.contact_id = :contact_id
  AND t.status = 'closed'
  [AND t.phone_number_id = :phone_number_id]
  AND (SELECT te.type
       FROM ticket_events te
       WHERE te.ticket_id = t.id
       ORDER BY te.created_at DESC, te.id DESC
       LIMIT 1) = 'window_expired_closed'
ORDER BY t.updated_at DESC, t.id DESC
LIMIT 1
```

Defensive properties, each intentional:

| Case | Behavior | Why |
|---|---|---|
| Ticket has **zero** `ticket_events` rows | Subquery yields `NULL`; `NULL = '…'` is `NULL`, not `TRUE` → no match → new ticket created | Fail-closed to today's behavior |
| Two events share the same `created_at` (second precision) | `id DESC` breaks the tie → newest insert wins | Deterministic; `created_at` alone is not unique |
| Latest event is anything else (e.g. a future human close) | No match → new ticket created, unchanged from today | The core requirement |
| **Multiple** eligible closed tickets for one contact | `ORDER BY t.updated_at DESC, t.id DESC LIMIT 1` | Avoids the `getOneOrNullResult()` non-unique exception the sibling methods can actually throw |
| `phoneNumberId` filter | Same optional-filter semantics as `findNotClosedByContact()` | CAP-7 multi-number parity |

`findNotClosedByContact()` and `findOpenByContact()` are **not modified**.

### `TransitionTicketStatusUseCase` (two new methods, `close()` untouched)

```php
public function closeDueToWindowExpiry(int $ticketId): array
// transactional + SELECT ... FOR UPDATE
// validity delegated to $this->stateMachine->transition($status, 'closed')  [D2 widening]
// reuses recordUnassignIfOwner(); UPDATE status='closed', closed_at=NOW(), updated_at=NOW()
// persists new TicketEvent($ticketId, TicketEventType::WINDOW_EXPIRED_CLOSED, 'system')

public function reopenDueToInboundMessage(int $ticketId): array
// transactional + SELECT ... FOR UPDATE
// guard 1: $ticket['status'] === CLOSED, else \InvalidArgumentException
// guard 2: latest ticket_events.type === WINDOW_EXPIRED_CLOSED, else \InvalidArgumentException
// NEVER calls $this->stateMachine — no shared-table entry exists or is added
// UPDATE status='active', closed_at=NULL, updated_at=NOW()
// persists new TicketEvent($ticketId, TicketEventType::WINDOW_REOPENED, 'system')
```

**Guard 2 is re-checked inside the locked transaction**, not trusted from the repository lookup: the two calls are separated in time and a concurrent close could change the reason in between. This also makes the method correct standalone, independent of its caller.

**Target status is `active`**, not `new` or `waiting`: `new` would misrepresent a ticket with existing history and contradict an already-set `first_response_at`; `waiting` means "waiting for the customer", which is factually false the instant the customer writes. `active` matches the existing `waiting → active` on-customer-response semantics at `HandleInboundMessageUseCase` step 7. `first_response_at` and `resolved_at` are left untouched; `closed_at` is cleared.

### `HandleInboundMessageUseCase::executeInternal()` — exact insertion

Between the current L262 and L265:

```php
$ticket = $this->ticketRepo->findNotClosedByContact($contact, $phoneNumberId);   // L262, unchanged
$isNewTicket = false;
$wasReopened = false;

if (!$ticket) {                                                    // NEW block
    $reopenable = $this->ticketRepo->findWindowExpiredClosedByContact($contact, $phoneNumberId);
    if ($reopenable !== null
        && $reopenable->getConversation()?->getId() === $conversation->getId()) {
        $this->transitionUseCase->reopenDueToInboundMessage((int) $reopenable->getId());
        $ticket = $reopenable;
        $wasReopened = true;
    }
}

if (!$ticket) { /* existing create-new branch, unchanged */ }
else {
    $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE);         // existing
    if ($wasReopened) { $this->em->refresh($ticket); }             // NEW — see gotcha
}
```

Two gotchas this placement resolves:
- **`em->refresh()` is mandatory.** `reopenDueToInboundMessage()` writes via raw SQL, so the managed entity still reports `status = 'closed'`. Without the refresh, step 7 reads a stale status and the returned `ticket_status` lies. Precedent: `HandleOutboundMessageUseCase` L179.
- **Conversation-identity guard.** Nothing in the codebase ever sets a conversation to non-`OPEN` (verified: `HandleInboundMessageUseCase:185` is the only `Conversation::setStatus()` call site), so `findOpenByContact()` always reuses the same conversation and the check passes. It is kept as a cheap fail-safe against cross-conversation ticket reuse if that ever changes.

Step 8 (~L296-301) additionally sets `$ticket->setLastInboundMessageAt($msg->getCreatedAt());` — the single writer for the new field (3.1).

### Caller integration (3.2)

| Caller | Insertion point | On expiry |
|---|---|---|
| `HandleOutboundMessageUseCase::executeTransactionWithRetry()` | Immediately after the ticket fetch at L141, **before** `$this->em->persist($msg)` (L198) and before any FSM transition | `throw new ConversationWindowExpiredException(...)` — transaction rolls back, no phantom message, no transition |
| `ProviderProxyController::send()` | Wrap the `processOutboundMessage()` call at L98 in a new `catch (ConversationWindowExpiredException $e)` (the call is currently **uncaught** — without this it would 500) | `422` + `['type' => 'CONVERSATION_WINDOW_EXPIRED', 'message' => …]`, mirroring the existing 422/`MESSAGE_TOO_OLD` shape. Same catch added to the media-send action. |
| `AutoReplyService::doMaybeSend()` | Before `$this->graphClient->sendMessage()` at L121 (which already precedes its persist at L137-145) | **Early `return`** with `logger->info`, not a throw — `maybeSend()` is fire-and-forget and "no auto-reply" is not an error |

### Command + compose service (3.5)

Mirrors `OutboxPublishCommand` → `OutboxPublisherService` exactly: the Command stays thin, the loop-safe logic lives in a service.

- `App\Command\CloseExpiredConversationWindowsCommand` — `#[AsCommand(name: 'app:conversation-window:close-expired')]`, options `--dry-run`, `--limit=200`, `--safety-margin=` (overrides the DI default).
- `App\Application\Service\ConversationWindowExpiryService::closeExpired(bool $dryRun, int $limit): int` — selects `status IN ('new','active','waiting','resolved') AND last_inbound_message_at IS NOT NULL AND last_inbound_message_at < :threshold ORDER BY last_inbound_message_at ASC LIMIT :limit`, then calls `closeDueToWindowExpiry()` per id, catching `\Throwable` **per ticket** so one bad row cannot abort the batch. No exemptions — a `new`/never-worked ticket closes like any other.

```yaml
  window-closer:
    build: { context: ./infra/docker/php }
    container_name: inbiox_window_closer
    volumes: [ "./apps/api:/var/www/html" ]
    command: sh -c "while true; do php bin/console app:conversation-window:close-expired; sleep 900; done"
    depends_on: [ php ]
    restart: always
```

### Timeline (`GetContactTimelineQuery`)

A third `UNION ALL` branch, column-compatible with the existing two (`type, occurred_at, content, ticket_id, ticket_status, assigned_to, agent_id, direction`):

```sql
SELECT CASE te.type
         WHEN 'window_expired_closed' THEN 'ticket.window_expired_closed'
         WHEN 'window_reopened'       THEN 'ticket.window_reopened'
       END AS type,
       te.created_at AS occurred_at, NULL AS content,
       t.id AS ticket_id, t.status AS ticket_status,
       NULL AS assigned_to, NULL AS agent_id, NULL AS direction
FROM ticket_events te
JOIN tickets t ON te.ticket_id = t.id
WHERE t.contact_id = :contact_id
  AND te.type IN ('window_expired_closed','window_reopened')     -- D6 allow-list
  [AND te.created_at < :before_date]
  [AND t.phone_number_id = :phone_number_id]
```

Built by a `$ticketEventWhere` fragment following the existing `$outboxWhere` / `$assignmentWhere` convention; `buildParams()`/`buildTypes()` need no new parameters (all three branches reuse `:contact_id`, `:before_date`, `:phone_number_id`).

---

## Mercure Event Flow (mandated by `rules.design`)

**No payload is added, removed, or modified. No new publisher.**

```
inbound message ──► HandleInboundMessageUseCase ──► MessageCreatedEvent{direction: inbound, createdAt}
                                                            │
                                              outbox_events ─┴─► publisher loop ──► Mercure ──► EventBusService
                                                                                                    │
                                     ticket.store.ts:107 (unread) ◄──────────────────────────────────┤
                                     conversation.store.ts:67 → addMessage() ◄──────────────────────┘
                                                                        │
                            ConversationComponent.lastInboundMessageAt (computed, read-only) ◄───────┘
                                                                        │
                                       ComposerComponent.windowExpired() flips back to false
```

The window state is **derived** at every hop. No component or service writes a window field into any store, and no polling exists — the only clock is a local 60s tick inside the composer (a UI refresh timer, not a server poll).

The reopen itself fires **no** ticket-level Mercure event, because `TicketUpdatedEvent` is deliberately not extended (constraint 4) and — as exploration verified — resolve/close already emit zero outbox events today. See Open Questions.

## Frontend Changes

| File | Change |
|---|---|
| `apps/web/src/app/core/models/ticket.model.ts` | `timestamps.last_inbound_message_at?: string \| null` — **optional**, so the object literals at `ticket.store.ts:144` and `:156` keep compiling |
| `containers/conversation/conversation.component.ts` | `lastInboundMessageAt = computed(...)`: `max(activeTicket()?.timestamps.last_inbound_message_at, latest inbound in conversationStore.messages())`. Sources already injected at L107-109. Read-only |
| `containers/conversation/conversation.component.html` L72-75 | `<app-composer [lastInboundMessageAt]="lastInboundMessageAt()" … />` |
| `components/composer/composer.component.ts` | First-ever `input()`: `lastInboundMessageAt = input<string \| null>(null)`; private `now` signal ticked by a 60s interval cleaned via `DestroyRef`; `windowExpired = computed(...)`; `send()` early-returns when expired |
| `components/composer/composer.component.html` | `@if (windowExpired()) { <p class="warning" role="status">…</p> } @else { existing input row }` |

The composer stays store-free (container/presentational): the container resolves the timestamp, the composer owns only the clock and the boolean.

**Accepted UI/backend skew**: the composer applies the full 24h; the backend applies 24h minus the safety margin. A narrow band therefore exists where the UI allows a send the backend rejects with 422 — surfaced by the existing error path. Exposing the margin over REST was rejected as out of scope. The UI is advisory; the backend is authoritative.

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/api/migrations/Version20260828000000.php` | Create | `tickets.last_inbound_message_at DATETIME NULL`; `INDEX idx_tickets_status_last_inbound (status, last_inbound_message_at)`; `INDEX idx_ticket_events_ticket_created (ticket_id, created_at, id)` — **`ticket_events` currently has no index but its PK** (verified in `Version20260505022731`), and both the reopen subquery and the timeline branch filter on `ticket_id`. `down()` drops all three |
| `src/Domain/Ticket/Ticket.php` | Modify | `#[ORM\Column(name: 'last_inbound_message_at', nullable: true)]` + getter/setter (mirrors `lastReadAt` at L64-65) |
| `src/Domain/Ticket/ConversationWindowPolicy.php` | Create | The predicate (D3) |
| `src/Domain/Ticket/TicketStateMachine.php` | Modify | D2 widening + docblock |
| `src/Domain/Event/TicketEventType.php` | Create | Two constants |
| `src/Exception/ConversationWindowExpiredException.php` | Create | Carries `ticketId`, `lastInboundMessageAt` |
| `src/Repository/TicketRepository.php` | Modify | `findWindowExpiredClosedByContact()` (additive only) |
| `src/Application/UseCase/HandleInboundMessageUseCase.php` | Modify | Reopen block (L262-279) + `setLastInboundMessageAt()` (L296-301) |
| `src/Application/UseCase/HandleOutboundMessageUseCase.php` | Modify | Guard after L141 |
| `src/Application/UseCase/TransitionTicketStatusUseCase.php` | Modify | Two new methods; `close()`/`execute()` untouched |
| `src/Application/Service/AutoReplyService.php` | Modify | Guard before L121 |
| `src/Application/Service/ConversationWindowExpiryService.php` | Create | Batch close |
| `src/Command/CloseExpiredConversationWindowsCommand.php` | Create | Thin command |
| `src/Controller/ProviderProxyController.php` | Modify | New catch around L98 (+ media action) |
| `src/Application/Query/GetTicketDetailQuery.php` | Modify | Select the column; add the `timestamps` key (L69-78) |
| `src/Application/Query/ListTicketsQuery.php` | Modify | Same (L89, L189-192) |
| `src/Application/Query/GetContactTimelineQuery.php` | Modify | Third `UNION ALL` branch |
| `config/services.yaml`, `.env` | Modify | Safety-margin binding |
| `docker-compose.yml` | Modify | `window-closer` service |
| 5 frontend files | Modify | Per the table above |

`TicketDetailDTO` / `TicketListItemDTO` need **no** change — `timestamps` is already a pass-through `array`.

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit | `ConversationWindowPolicy` | Boundary table: exactly-24h, 24h±margin, null under both semantics (D8) |
| Unit | `TicketStateMachine` | `new/active/waiting → closed` now valid; **every** `closed → *` still invalid; `isTerminal('closed')` still true |
| Integration | Guard-before-persist (D4 substitute) | Expired window → assert zero new `messages` rows **and** unchanged ticket status, per caller |
| Integration | Reopen — positive | Inbound on a `window_expired_closed` ticket reuses the same `ticket_id` and lands `active` |
| Integration | Reopen — negative | Inbound on a ticket closed any other way (or with zero `ticket_events`) still creates a new ticket |
| Integration | Isolation | `POST /tickets/{id}/transition` targeting any status on a `window_expired_closed` ticket → 4xx; `POST /tickets/{id}/close` still rejects non-`resolved` |
| Integration | Command | Only expired tickets close; `last_inbound_message_at IS NULL` never closes; `--dry-run` writes nothing |
| Integration | Timeline | `window_expired_closed` + `window_reopened` appear; `tag_added` does not (D6) |
| Frontend | — | Karma unrunnable (no Chrome). Gate: `ng build --configuration production` exit 0 |

Backend baseline: must not exceed the 58 pre-existing PHPUnit failures.

## Threat Matrix

| Boundary | Applicable | Note |
|---|:--:|---|
| Shell / subprocess | Applicable | `window-closer` runs `sh -c "while true; …"`. The command takes **no** user-supplied arguments and no untrusted value reaches the shell string — identical shape to the shipped `publisher` service. Required behavior: a non-zero exit must not kill the loop (`restart: always` + per-ticket `\Throwable` catch inside the service) |
| Routing | N/A | No new HTTP route; one new catch on an existing action |
| VCS/PR automation | N/A | None |
| Executable-file classification | N/A | None |
| Process integration | Applicable | Covered by the shell row above |

## Migration / Rollout

1. Migration (additive, nullable — safe on a live table).
2. Backend code; the column starts `NULL` everywhere, so `isExpiredForClosing()` closes nothing until inbound traffic backfills it (D8, mass-close mitigation).
3. Frontend (independently revertable — falls back to always-enabled).
4. `window-closer` **last**, after a `--dry-run` on production data.

Rollback: stop `window-closer` **first**, then revert code, then `down()` the migration together with the serializer keys.

## Open Questions

- [ ] **Safety-margin numeric default** — `sdd-spec`'s call, running in parallel. Read `specs/` and set `CONVERSATION_WINDOW_SAFETY_MARGIN_MINUTES` once both artifacts land. The mechanism here is configuration-driven and needs no code change for any value.
- [ ] **A reopened ticket may not appear live in the agent's ticket list.** No ticket-level Mercure event fires (constraint 4 forbids extending `TicketUpdatedEvent`, and exploration confirmed resolve/close emit zero outbox events today). `ticket.store.ts`'s `MessageAdded` handler only bumps unread for tickets already in `tickets()`, and a closed ticket generally is not. The reopen is fully correct in the database and appears on the next list load. Fixing this properly means giving status changes their own event — a Fase 1 concern, deliberately out of scope here. **Flag for `sdd-tasks` as a known limitation, not a defect.**
