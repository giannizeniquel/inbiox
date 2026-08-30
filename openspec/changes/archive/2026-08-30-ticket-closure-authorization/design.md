# Design: Ticket Closure Authorization and Audit Trail

**Change:** `ticket-closure-authorization` (Sprint 16 Fase 1)
**Grounded by:** `exploration.md` (verified call chains), `proposal.md` (fixed constraints 1-6).
**Extends:** `whatsapp-24h-window-compliance/design.md` — reuses its `TicketEventType`, its single `ticket_events` `UNION ALL` timeline branch, and its `LEFT JOIN`-actor pattern. This document decides HOW; `specs/` decides WHAT.

## Technical Approach

Four seams, all additive, no migration:

1. **Authorization seam** — `TicketWorkflowController` gains a per-ticket `TicketVoter::CLOSE` check, applied to `resolve()`, `close()`, and `transition()` *only when the target status is `resolved`/`closed`*. Structural pattern copied from `TicketController::assign()`: `Security::isGranted()` + hand-built `JsonResponse` (the controller does not extend `AbstractController`, so `denyAccessUnlessGranted()` is unavailable).
2. **Actor seam** — one optional trailing `string $actor = 'system'` parameter threaded through `resolve()` / `close()` / `execute()` down to `recordUnassignIfOwner()`. Every existing caller stays valid unchanged and keeps writing `'system'`.
3. **Classification seam** — the use case returns the pre-transition owner id it already read *under its own `FOR UPDATE` lock*; the controller classifies owner / override / unowned from that value. No second read, no new lock, no TOCTOU on the audit label.
4. **Read seam** — the existing third `UNION ALL` branch in `GetContactTimelineQuery` gains six allow-list entries, six `CASE` arms, and two `LEFT JOIN`s. **No fourth branch.**

---

## Architecture Decisions

### D1 — Wire `TicketVoter::CLOSE`. Reusing `ASSIGN` is not merely coupled, it is broken.

**Chosen: wire `CLOSE`.**

The proposal framed this as a semantic-coupling tradeoff. Reading the voter source settles it on correctness grounds instead:

`TicketVoter::voteOnAttribute()` (`apps/api/src/Application/Security/Voter/TicketVoter.php:74-82`) opens with an `ASSIGN`-only state guard that **throws**, it does not return false:

```php
if ($attribute === self::ASSIGN) {
    $status = $ticket->getStatus();
    if ($status === TicketStatus::RESOLVED->value || $status === TicketStatus::CLOSED->value) {
        throw new \App\Exception\StateConflictException(...);   // → 409
    }
}
```

`TransitionTicketStatusUseCase::close()` requires `status === 'resolved'` (`TransitionTicketStatusUseCase.php:273-275`). So **every legitimate call to `POST /tickets/{id}/close` targets a `resolved` ticket** — which is exactly the state the `ASSIGN` guard rejects. Reusing `ASSIGN` would make `isGranted('ASSIGN', $ticket)` throw `StateConflictException` on 100% of valid closes, turning the endpoint into a permanent 409. The same applies to `POST /tickets/{id}/transition {"status":"closed"}` from `resolved`.

`CLOSE` does not enter that branch and falls through to the generic rule — which is already the required rule verbatim: `ROLE_SUPERVISOR`+ always granted (role hierarchy makes `ROLE_ADMIN` a supervisor, `security.yaml`), `ROLE_AGENT` granted only when `ticket.assignedAgentId === user.agent.id`, denied when the user has no linked `Agent` (R1) or the ticket is unassigned (R2).

| Option | Verdict |
|---|---|
| Reuse `ASSIGN` | **Rejected** — throws `StateConflictException` for every `resolved` ticket, i.e. every real close. Also couples two permissions `openspec/specs/authorization/spec.md`'s RBAC matrix already separates ("Reassign tickets" ❌ agent / "Close own tickets" ✅ agent) |
| **Wire `CLOSE`** | **Chosen** — semantically honest, no state-guard collision, leaves `ASSIGN` free to diverge, and its behavior is already covered in isolation by the pre-existing `tests/Security/VoterTest.php` cases (`ADMIN/SUPERVISOR CLOSE other ticket`, `AGENT CLOSE own ticket`, `AGENT CLOSE other ticket`) |

**Concrete `voteOnAttribute()` change required: none.** `supports()` already accepts `CLOSE` (line 59); the generic branch already implements the rule. The only edit to `TicketVoter.php` is its **class docblock** (lines 30-44), which currently states that `CLOSE` is "reserved/dead code … currently a no-op if ever called" and that the logic "was written under the (incorrect, for VIEW) assumption". That text becomes false the moment a controller calls it; leaving it would actively mislead the next reader. The `VIEW`/`EDIT` paragraph stays as-is — they remain unwired.

**Consequence, deliberately not "fixed" here:** `CLOSE` inherits R2 (deny when `assignedAgentId === null`). See D3 for why that matters and what it implies for the `/close` endpoint.

### D2 — `TicketEventType` constant set: six constants, all reachable

**Investigation of the "is unowned reachable for resolve?" question, with evidence:**

`resolve()` is `execute($ticketId, 'resolved', true, true)` (`TransitionTicketStatusUseCase.php:253-256`). There is **no** owner check anywhere in that path — not in `ResolveTicketUseCase::execute()` (a bare delegate, `ResolveTicketUseCase.php:25-28`), not in `execute()`, not in `TicketStateMachine` (`active|waiting → resolved`, no ownership predicate). The existing code in fact *anticipates* the unowned case: `execute()` guards the audit block with `&& !empty($ticket['assigned_agent_id'])` (line 96).

Two concrete reachable paths for an unowned `active`/`waiting` ticket:

1. **`AutoReopenRule`** — `resolved → waiting` (`AutoReopenRule.php:36-41`). Its precondition is a `resolved` ticket, and (see below) every `resolved` ticket is already unowned. The reopened `waiting` ticket therefore has `assigned_agent_id = NULL` and is directly resolvable again.
2. **Agentless sender** — `HandleOutboundMessageUseCase` only auto-assigns when the sending user has a linked `Agent` (`HandleOutboundMessageUseCase.php:167-181`); the `new → active` transition at line 217-220 runs regardless. A supervisor/admin `User` with no `Agent` row produces an `active`, unowned ticket.

**So "unowned" is reachable for RESOLVE. Symmetric constant set confirmed — nothing invented, nothing unreachable.**

```php
final class TicketEventType
{
    public const WINDOW_EXPIRED_CLOSED = 'window_expired_closed';   // existing, unchanged
    public const WINDOW_REOPENED       = 'window_reopened';         // existing, unchanged

    public const MANUAL_RESOLVED_OWNER    = 'manual_resolved_owner';      // 21 chars
    public const MANUAL_RESOLVED_OVERRIDE = 'manual_resolved_override';   // 24 chars
    public const MANUAL_RESOLVED_UNOWNED  = 'manual_resolved_unowned';    // 23 chars
    public const MANUAL_CLOSED_OWNER      = 'manual_closed_owner';        // 19 chars
    public const MANUAL_CLOSED_OVERRIDE   = 'manual_closed_override';     // 22 chars
    public const MANUAL_CLOSED_UNOWNED    = 'manual_closed_unowned';      // 21 chars
}
```

`ticket_events.type` is `VARCHAR(50)` (`Version20260505022731.php:27`). Longest value is 24 characters — **26 characters of headroom**, comfortably clear. No migration.

Reachability matrix (this is the exhaustive justification that no constant is dead):

| Constant | Reachable via | Actor |
|---|---|---|
| `manual_resolved_owner` | `POST /resolve` or `/transition{resolved}` on an owned `active`/`waiting` ticket | its owning agent |
| `manual_resolved_override` | same, ticket owned by a different agent | supervisor / admin |
| `manual_resolved_unowned` | same, on an `AutoReopenRule`-reopened or agentless-sender ticket | supervisor / admin (agents are denied by R2) |
| `manual_closed_owner` | `POST /transition{closed}` from `active`/`waiting` (the FSM widening from the sibling change) on an owned ticket | its owning agent |
| `manual_closed_override` | same, ticket owned by a different agent | supervisor / admin |
| `manual_closed_unowned` | `POST /close` (**always** — see D3), and `/transition{closed}` on an unowned ticket | supervisor / admin |

### D3 — Verified consequence: `POST /close` is structurally always an *unowned* close, and therefore supervisor/admin-only

This is the most consequential finding of the design phase and it is **not** hypothetical.

Chain of evidence:
1. Resolving a ticket runs `recordUnassignIfOwner()` (`TransitionTicketStatusUseCase.php:95-102`), which calls `AssignmentService::recordOwnershipChange(agent: null, …)`.
2. That method executes `$ticket->setAssignedAgentId($agent?->getId())` — i.e. `NULL` (`AssignmentService.php:67`) — and `execute()` explicitly `flush()`es it inside the transaction (line 101, with a comment saying so).
3. Therefore **every ticket in `resolved` status has `assigned_agent_id = NULL`.**
4. Re-acquiring ownership afterwards is impossible through the API: `POST /tickets/{id}/assign` is gated by `TicketVoter::ASSIGN`, whose state guard throws `StateConflictException` (409) for `resolved` tickets — for every role, including admin, because the throw precedes the role checks.
5. `close()` requires `status === 'resolved'` (line 273).

⇒ `POST /tickets/{id}/close` always sees `assignedAgentId === null`, so `TicketVoter`'s R2 (`TicketVoter.php:100-102`) denies **every** `ROLE_AGENT`, including the agent who resolved the ticket seconds earlier. Only supervisors and admins can use `/close`.

**Decision: accept this and do not special-case the voter.** Rationale:

- The alternative — teaching `CLOSE` to fall back on "the acting agent is the `previous_agent` of the latest `UNASSIGN` assignment row" — puts a DB query inside a voter (voters run per-request, possibly per-object in list contexts), and re-establishes `assignments` as an ownership source of truth for a case constraint 1 explicitly moved into `ticket_events`. Rejected.
- The other alternative — making the voter grant `CLOSE` on unowned tickets to any agent — is a genuine permission *widening* (any agent could close any unowned ticket) that no spec requests. Rejected.
- The agent's legitimate "close my own ticket" path already exists and is *owned* at that moment: `POST /tickets/{id}/transition {"status":"closed"}` from `active`/`waiting`, enabled by the sibling change's FSM widening (`TicketStateMachine.php:34-52`). This is exactly the path that makes `manual_closed_owner` reachable in D2.

**Flagged for `sdd-spec` / product:** `openspec/specs/authorization/spec.md`'s RBAC matrix says "Close own tickets ✅" for agents. Under this design that is true via `/transition{closed}` and false via `/close`. The delta spec must say which endpoint the requirement means, or the product must accept that `/close` (the resolved→closed step) is a supervisor/admin action. **This design does not silently redefine the matrix.** See Open Questions.

### D4 — The pre-transition owner is returned by the use case, not re-read by the controller

The classification needs `assigned_agent_id` **as it was immediately before the transition**, because the transition itself nulls it (D3, step 2). A post-transition read would classify *every* action as `unowned`. A pre-transition read in the controller would be a second, unlocked read — a real TOCTOU against a concurrent `POST /assign`.

Both problems disappear by returning the value the use case *already has*. `execute()` and `close()` both fetch the row with `SELECT * FROM tickets WHERE id = ? FOR UPDATE` inside their own transaction. One additive key:

```php
return [
    'ticket_id'                   => $ticketId,
    'previous_status'             => $currentStatus,
    'new_status'                  => $newStatus,
    'previous_assigned_agent_id'  => isset($ticket['assigned_agent_id'])   // NEW
        ? (int) $ticket['assigned_agent_id'] : null,
    'reason'                      => 'success',
];
```

**Does the classification need its own lock or transaction? No — reasoned, not assumed.**

- The value is captured under `FOR UPDATE` and returned by value. Any concurrent reassignment either committed *before* the lock (and is therefore reflected in the captured value) or blocks *behind* it (and lands after the ticket is already `resolved`/`closed`, at which point `TicketVoter::ASSIGN`'s state guard rejects it with 409). There is no interleaving that yields a stale owner id.
- The classification is **read-then-classify**, not read-then-write: nothing in the database is decided by it except the literal `type` string of an append-only audit row. Holding the row lock across the `TicketEvent` INSERT would buy no additional consistency and would extend a write lock across an extra round trip.
- Widening the transaction to include the event write would also require moving the event write into the use case — forbidden by constraint 2.

Applied to `resolve()` (which delegates to `execute()`) the key propagates for free. `closeDueToWindowExpiry()` and `reopenDueToInboundMessage()` are **not** modified — no caller classifies them.

**REST contract is kept byte-identical**: the controller `unset()`s `previous_assigned_agent_id` before `new JsonResponse($result)`. This change adds no field to any API response and no frontend file is touched. `HandleOutboundMessageUseCase` discards `execute()`'s return value entirely (it builds its own `$resultData` at `HandleOutboundMessageUseCase.php:253-260`), so the new key cannot leak into the `ProcessedEvent` idempotency payload either.

### D5 — Actor identity format: `u:{userId}` in `ticket_events.source`, resolved by join

`TicketEvent.source` stays `varchar(20)` (constraint 3). It stores a **namespaced user reference**, `sprintf('u:%d', $user->getId())`.

Why namespaced, and why the *user* rather than the *agent*:

- A bare numeric id in `source` would be silently ambiguous: the timeline's `LEFT JOIN agents ag ON te.source = ag.id` would happily match agent #7 for a non-agent user #7 — a wrong-name-in-the-audit-trail bug. The proposal's own risk row ("`source` holding a non-agent user id") anticipates this; the prefix makes the mismatch explicit rather than accidental.
- Keying on the **user** (not the agent) is the only representation that covers every actor: `ROLE_ADMIN`/`ROLE_SUPERVISOR` users may have **no** linked `Agent` (`Agent.user_id` is nullable, `Agent.php:27-29`; `TicketVoter` R1 handles exactly that user). An agent-keyed `source` would lose those actors entirely. From a user id the agent is still reachable one hop away (`agents.user_id` is `unique`, so the join cannot multiply rows), and — decisively — the `Admin`/`Sup`/`Agente` label of constraint 5 comes from `user.roles`, which is unreachable from `agents` alone.
- Width: `u:` + 18 digits fits `varchar(20)`. Real ids are far below that; a >18-digit user id is not a reachable state on this dataset.

`assignments.assigned_by` (`varchar(100)`, free text, currently the literal `'system'` at `TransitionTicketStatusUseCase.php:59`) receives the **same** `u:{userId}` string, for a single canonical actor representation across both audit tables. It is not rendered anywhere in the timeline today (the `assignments` branch joins on `a.agent_id`, not `assigned_by`), so this changes no read path.

### D6 — Role label and display name are computed at read time, in SQL

Constraint 3 mandates join-at-read-time; constraint 5 fixes the labels and their check order. Both live in the timeline branch's `CASE`, ordered `ROLE_ADMIN` → `ROLE_SUPERVISOR` → `ROLE_AGENT`, which is exactly the ordering constraint 5 requires (an admin is also a supervisor under the hierarchy, so admin must be tested first).

`user.roles` is a native MySQL `JSON` column (`Version20260616000001.php:24` — `roles JSON NOT NULL`), so `JSON_CONTAINS(u.roles, '"ROLE_ADMIN"')` is valid. Note this reads the **raw stored roles**, not the hierarchy-expanded set — which is correct and is precisely why the ordered `CASE` is required rather than a naive "has supervisor" test.

`TimelineEventDTO` is **unchanged** (constraint: no DTO change). The label is folded into the existing `assigned_to` string as `"{label} {name}"` (e.g. `"Sup Ana Pérez"`); `agent_id` carries `ag.id`, `NULL` for an agentless admin. Display name falls back to `u.email` when the actor has no linked `Agent`; the timeline is an internal agent-facing view, so an internal staff email is acceptable there. (Alternative considered: emit the bare label with no name. Rejected — it makes two different admins indistinguishable, defeating the point of the change.)

### D7 — `execute()` sets `closed_at` when it lands on `closed` (scope-boundary decision, additive one-liner)

D3 routes the agent's own-ticket close through `POST /transition {"status":"closed"}`. That path runs `execute()`, whose update list (`TransitionTicketStatusUseCase.php:104-116`) sets `status`, `updated_at`, and conditionally `first_response_at`/`resolved_at` — but **never `closed_at`**, unlike `close()` (line 284) and `closeDueToWindowExpiry()` (line 167). This gap was introduced by the sibling change's FSM widening (`new|active|waiting → closed`) and is currently latent; this design makes it load-bearing by designating `/transition{closed}` as the primary owner-close path.

Add, alongside the existing `resolved_at` conditional:

```php
if ($newStatus === TicketStatus::CLOSED->value && empty($ticket['closed_at'])) {
    $updates[] = 'closed_at = NOW()';
}
```

Safe for every caller: `HandleOutbound`/`HandleInboundMessageUseCase` never target `closed` (verified, exploration 1.3); `AutoCloseRule` and `AutoReopenRule` do not route through this branch; `close()` and `closeDueToWindowExpiry()` already set it themselves. No read path depends on `closed_at IS NULL` (the sibling's `findWindowExpiredClosedByContact()` keys on `ticket_events`, not `closed_at`).

**If `sdd-tasks` judges this out of scope, it can be dropped — nothing else in this design depends on `closed_at`.** It is called out here rather than smuggled in.

### D8 — Audit-write failure does not fail the request

The `TicketEvent` INSERT happens after the use case's transaction has already committed (constraint 2 forbids moving it inside). If that INSERT throws, the ticket is closed with no audit row.

**Decision: log at `error` with the full classification payload and still return the successful transition result.** Returning 500 after a committed close is strictly worse: the client retries, the retry hits `'Only resolved tickets can be closed'` → 400, and the operator sees a phantom failure for an action that actually succeeded. The failure mode is a single dependency-free INSERT, so a failure implies the database is unavailable — in which case the transition would almost certainly have failed first. The error log carries `ticket_id`, `type`, and `source`, making the row reconstructible. Recorded as an Open Question for a future outbox-backed audit write.

---

## Authorization + Audit Flow — Sequence Diagram

```
Client        TicketWorkflowController      Security/TicketVoter   TransitionTicketStatusUseCase   AssignmentService     MySQL
  │                     │                            │                          │                         │              │
  ├ POST /tickets/42/transition ──────────────────────────────────────────────────────────────────────────────────────►  │
  │   {"status":"closed"}│                            │                          │                         │              │
  │                     ├─ decode body; $target='closed'                         │                         │              │
  │                     ├─ gate applies? $target ∈ {resolved, closed} → YES ─────┤                         │              │
  │                     │   (for /resolve and /close the gate is unconditional)  │                         │              │
  │                     │                            │                          │                         │              │
  │                     ├─ ticketRepo->findById(42) ─────────────────────────────────────────────────────────────────►   │
  │                     │◄─ Ticket{status:'active', assignedAgentId:7} ────────────────────────────────────────────────   │
  │                     │      └─ null? → SKIP the voter, fall through to the use case's existing 400 (contract kept)     │
  │                     │                            │                          │                         │              │
  │                     ├─ isGranted(CLOSE, $ticket) ►│                          │                         │              │
  │                     │                            ├─ NOT the ASSIGN branch → no StateConflictException (D1)            │
  │                     │                            ├─ ROLE_SUPERVISOR? → grant                            │              │
  │                     │                            ├─ ROLE_AGENT → agent!==null && ticket.owner===agent.id              │
  │                     │◄──────── false ────────────┤                          │                         │              │
  │◄─ 403 {error, code, reason, action, ticket_id, message}  ── STOP, nothing written ──────────────────────────────────  │
  │                     │◄──────── true ─────────────┤                          │                         │              │
  │                     │                            │                          │                         │              │
  │                     ├─ execute(42,'closed',…, actor:'u:9') ─────────────────►│  BEGIN                  │              │
  │                     │                            │                          ├─ SELECT * … FOR UPDATE ───────────────► │
  │                     │                            │                          │  $prevOwner = 7  ◄── captured under lock│
  │                     │                            │                          ├─ stateMachine->transition('active','closed')
  │                     │                            │                          │                         │              │
  │                     │                            │       owner exists ──────┤ recordUnassignIfOwner(42,'u:9')        │
  │                     │                            │                          ├─ recordOwnershipChange ►├ INSERT assignments
  │                     │                            │                          │   agent:null,           │  (assigned_by='u:9',
  │                     │                            │                          │   prev:Agent#7,         │   type=UNASSIGN) ──►│
  │                     │                            │                          │   assignedBy:'u:9'      ├ ticket.assignedAgentId=NULL
  │                     │                            │                          ├─ em->flush() ──────────────────────────►│
  │                     │                            │       no owner ──────────┤ (guard at L96 false → nothing written)  │
  │                     │                            │                          ├─ UPDATE tickets SET status,updated_at,  │
  │                     │                            │                          │      closed_at=NOW() (D7) ────────────►│
  │                     │◄─ {…, previous_assigned_agent_id: 7} ─────────────────┤  COMMIT                 │              │
  │                     │                            │                          │                         │              │
  │                     ├─ classifier->classify('closed', prevOwner:7, actingAgent:7)                      │              │
  │                     │      7 === 7          → MANUAL_CLOSED_OWNER            │                         │              │
  │                     │      prevOwner null   → MANUAL_CLOSED_UNOWNED          │                         │              │
  │                     │      otherwise        → MANUAL_CLOSED_OVERRIDE         │                         │              │
  │                     │                            │                          │                         │              │
  │                     ├─ persist TicketEvent(42, MANUAL_CLOSED_OWNER, 'u:9'); flush ────────────────────────────────►  │
  │                     │      └─ on \Throwable: logger->error(ticket_id,type,source); DO NOT fail the request (D8)      │
  │                     │                            │                          │                         │              │
  │                     ├─ unset($result['previous_assigned_agent_id'])   ← REST contract unchanged (D4)   │              │
  │◄─ 200 {ticket_id, previous_status, new_status, reason} ──────────────────────────────────────────────────────────────│
```

`AutoCloseRule` / `closeDueToWindowExpiry()` never enter this diagram: they call `close()` / `closeDueToWindowExpiry()` directly with no `$actor`, so the default `'system'` applies and their own event emission is untouched.

---

## Interfaces / Contracts

### `App\Domain\Event\ClosureAuditClassifier` (new)

A final, dependency-free service — same shape as the sibling design's `ConversationWindowPolicy` (D3 there): no Doctrine, no HTTP, unit-testable in isolation, which is what strict TDD needs first.

```php
final class ClosureAuditClassifier
{
    /**
     * @param string   $newStatus        'resolved' | 'closed' (post-transition)
     * @param int|null $previousOwnerId  ticket.assigned_agent_id captured under FOR UPDATE (D4)
     * @param int|null $actingAgentId    $user->getAgent()?->getId(), null for an agentless admin
     */
    public function classify(string $newStatus, ?int $previousOwnerId, ?int $actingAgentId): string;

    /** Compact, namespaced actor reference for TicketEvent.source / Assignment.assignedBy (D5). */
    public function actorReference(User $user): string;   // "u:{id}"
}
```

`classify()` truth table (an agentless admin never equals a non-null owner, so it always yields OVERRIDE or UNOWNED — correct):

| `previousOwnerId` | `actingAgentId` | `resolved` | `closed` |
|---|---|---|---|
| `null` | any | `MANUAL_RESOLVED_UNOWNED` | `MANUAL_CLOSED_UNOWNED` |
| `7` | `7` | `MANUAL_RESOLVED_OWNER` | `MANUAL_CLOSED_OWNER` |
| `7` | `3` or `null` | `MANUAL_RESOLVED_OVERRIDE` | `MANUAL_CLOSED_OVERRIDE` |

### `TicketWorkflowController` (modified)

New constructor dependencies (mirroring `TicketController`'s `ticketRepo` + `security`, and `AutoCloseRule`'s `em` for the event write):

```php
public function __construct(
    private TransitionTicketStatusUseCase $transitionUseCase,
    private ResolveTicketUseCase $resolveTicketUseCase,
    private LoggerInterface $logger,
    private TicketRepository $ticketRepo,          // NEW — voter subject
    private Security $security,                    // NEW — isGranted (class does NOT extend AbstractController)
    private EntityManagerInterface $em,            // NEW — TicketEvent persist
    private ClosureAuditClassifier $classifier,    // NEW
) {}
```

The authenticated user is obtained with `#[CurrentUser] ?User $user` on each action — the existing pattern in this codebase (`MercureTokenController.php:22`, `ProviderProxyController.php:48` and `:299`). It is an argument value resolver and works on a plain controller class. `?User` is nullable for the same reason those two are: `#[IsGranted('ROLE_AGENT')]` already guarantees a user, and a null check is a cheap fail-closed (`403`).

**Gating, per action:**

| Action | Gated | How |
|---|---|---|
| `close(int $id)` | Always | Load ticket, `isGranted(TicketVoter::CLOSE, $ticket)` |
| `resolve(int $id)` | Always | Same |
| `transition(int $id, Request)` | **Conditionally** | Only when `$targetStatus === TicketStatus::RESOLVED->value \|\| $targetStatus === TicketStatus::CLOSED->value`, evaluated **after** the existing `if (!$targetStatus) return 400` |

`transition()`'s gate compares the raw client string against the two enum values by exact match. A non-matching value (`"CLOSED"`, `"Closed"`, garbage) is not gated — and cannot be a bypass, because `TicketStateMachine::TRANSITIONS` is keyed by the same lowercase enum values, so any such target throws `InvalidTransitionException` → the existing 422. `new→active`, `active→waiting`, `waiting→active`, `resolved→waiting` are **never** gated, exactly per the proposal's over-blocking risk row.

**Ticket-not-found handling:** the pre-load returns `null` → **skip the voter and fall through to the use case**, which throws `\InvalidArgumentException('Ticket not found: N')` → the existing 400. Deliberately *not* a new 404: `/resolve` and `/close` return 400 for a missing ticket today, and this change must not alter that contract (no spec covers it, and no frontend change is in scope). This is safe — there is nothing to authorize on a nonexistent ticket, and ticket existence is not confidential in a shared inbox (`TicketVoter` docblock: VIEW is intentionally unrestricted).

**403 body (constraint 4 — custom to this action, not `TicketController::assign()`'s `{'error':'Forbidden'}`):**

```json
{
  "error": "Forbidden",
  "code": "TICKET_CLOSURE_FORBIDDEN",
  "reason": "not_ticket_owner",
  "action": "close",
  "ticket_id": 42,
  "message": "Only the ticket's assigned agent, a supervisor, or an admin can resolve or close this ticket."
}
```

`reason` is derived in the controller from values already in hand — the voter itself only returns a bool, so no voter change is needed:

| Condition | `reason` |
|---|---|
| `$user->getAgent() === null` | `no_linked_agent` |
| `$ticket->getAssignedAgentId() === null` | `unowned_ticket` |
| otherwise | `not_ticket_owner` |

`action` is `"resolve"`, `"close"`, or `"transition"`. Status is `403`.

### `TransitionTicketStatusUseCase` (modified — signatures only, no event emission)

```php
private function recordUnassignIfOwner(int $ticketId, string $actor = 'system'): void
//   … unchanged body; the ONLY edit is  assignedBy: $actor  at line 59.
//   All three early-returns and both call-site guards stay exactly as they are (constraints 1 and 6).

public function execute(int $ticketId, string $targetStatus, bool $setFirstResponse = false,
                        bool $setResolved = false, string $actor = 'system'): array
//   passes $actor to recordUnassignIfOwner(); adds previous_assigned_agent_id to the result (D4);
//   adds the closed_at conditional (D7).

public function resolve(int $ticketId, string $actor = 'system'): array
//   → execute($ticketId, RESOLVED, true, true, $actor)

public function close(int $ticketId, string $actor = 'system'): array
//   passes $actor to recordUnassignIfOwner(); adds previous_assigned_agent_id to the result.

public function closeDueToWindowExpiry(int $ticketId): array   // UNCHANGED
public function reopenDueToInboundMessage(int $ticketId): array // UNCHANGED
```

`ResolveTicketUseCase::execute(int $ticketId, string $actor = 'system')` forwards `$actor`. It is a bare delegate (`ResolveTicketUseCase.php:25-28`); nothing else changes.

**Every automatic caller stays valid, unchanged, and still attributed to `'system'` — verified call site by call site:**

| Caller | Call | Effect |
|---|---|---|
| `AutoCloseRule::execute()` L49 | `close($id)` | `$actor` defaults to `'system'`; its own `auto_closed` `TicketEvent` (L52) is untouched → exactly one event, `source='system'` |
| `AutoReopenRule::execute()` L36 | `execute($id, WAITING, false, false)` | 4 positional args, new param is 5th → default applies. Target is `waiting`, so the audit block at L95 never fires anyway |
| `HandleOutboundMessageUseCase` L233 | `execute($id, $newStatus, $flag, false)` | Same — 4 positional args. Never targets `resolved`/`closed` (L217-229) |
| `HandleInboundMessageUseCase` L323 | `execute(...)` | Same |
| `closeDueToWindowExpiry()` L162 | `recordUnassignIfOwner($ticketId)` | Not modified; default `'system'` preserved. Its `window_expired_closed` event is untouched |

No caller is required to pass an actor. The proposal's "signature change breaks automatic callers" risk is closed by construction: every new parameter is optional and trailing.

### `GetContactTimelineQuery` (modified — the existing branch, not a new one)

Allow-list (`GetContactTimelineQuery.php:75`) gains the six constants; the `CASE` (lines 136-139) gains six arms; the branch gains two `LEFT JOIN`s. Everything else — `buildParams()`, `buildTypes()`, the outer `SELECT`, the other two branches — is unchanged (all three branches keep reusing `:contact_id`, `:before_date`, `:phone_number_id`).

```sql
-- Ticket audit events (window compliance + manual closure) — single allow-listed branch
SELECT
    CASE te.type
        WHEN 'window_expired_closed'     THEN 'ticket.window_expired_closed'
        WHEN 'window_reopened'           THEN 'ticket.window_reopened'
        WHEN 'manual_resolved_owner'     THEN 'ticket.manual_resolved_owner'
        WHEN 'manual_resolved_override'  THEN 'ticket.manual_resolved_override'
        WHEN 'manual_resolved_unowned'   THEN 'ticket.manual_resolved_unowned'
        WHEN 'manual_closed_owner'       THEN 'ticket.manual_closed_owner'
        WHEN 'manual_closed_override'    THEN 'ticket.manual_closed_override'
        WHEN 'manual_closed_unowned'     THEN 'ticket.manual_closed_unowned'
    END AS type,
    te.created_at AS occurred_at,
    NULL AS content,
    t.id AS ticket_id,
    t.status AS ticket_status,
    CASE WHEN u.id IS NULL THEN NULL ELSE CONCAT(
        CASE
            WHEN JSON_CONTAINS(u.roles, '"ROLE_ADMIN"')      THEN 'Admin'   -- constraint 5: ADMIN first
            WHEN JSON_CONTAINS(u.roles, '"ROLE_SUPERVISOR"') THEN 'Sup'
            ELSE 'Agente'
        END,
        ' ', COALESCE(ag.name, u.email)
    ) END AS assigned_to,
    ag.id AS agent_id,
    NULL AS direction
FROM ticket_events te
JOIN tickets t       ON te.ticket_id = t.id
LEFT JOIN `user` u   ON te.source = CONCAT('u:', u.id)   -- D5: namespaced, so 'system' never matches
LEFT JOIN agents ag  ON ag.user_id = u.id                -- agents.user_id is UNIQUE → no row multiplication
WHERE {$ticketEventWhere}
```

Properties, each intentional:

| Case | Behavior | Why |
|---|---|---|
| `source = 'system'` (window/auto rows) | `'system'` never equals `'u:<n>'` → both joins yield NULL → `assigned_to`/`agent_id` NULL | **Byte-identical output to today** for the sibling change's rows — zero regression, which is why one branch suffices |
| Actor user deleted | `LEFT JOIN` → row still renders, actor NULL | Same guarantee the `assignments` branch already gives (proposal risk row) |
| Actor is an admin with no linked `Agent` | `u` matches, `ag` does not → `"Admin admin@…"`, `agent_id` NULL | Actor is never lost |
| `manual_*` types not in the allow-list | Row simply not selected | The sibling's D6 forward-compatible allow-list property; also what makes the rollback plan a pure code revert |
| Legacy `auto_closed` / `tag_added` | Still excluded | Allow-list unchanged for them — no scope creep into the timeline |

`TimelineEventDTO` is unchanged (D6).

---

## File Changes

| File | Action | Description |
|---|---|---|
| `src/Controller/TicketWorkflowController.php` | Modify | `#[CurrentUser]`; 4 new deps; per-action `CLOSE` gate (conditional in `transition()`); custom 403; classify + persist `TicketEvent`; `unset()` the internal result key |
| `src/Application/Security/Voter/TicketVoter.php` | Modify | **Docblock only** — `CLOSE` is no longer dead code (D1). Zero logic change |
| `src/Application/UseCase/TransitionTicketStatusUseCase.php` | Modify | Optional trailing `$actor` on `execute`/`resolve`/`close`/`recordUnassignIfOwner`; `assignedBy: $actor`; `previous_assigned_agent_id` in results; `closed_at` conditional (D7) |
| `src/Application/UseCase/ResolveTicketUseCase.php` | Modify | Forward `$actor` |
| `src/Domain/Event/TicketEventType.php` | Modify | Six new constants (D2) |
| `src/Domain/Event/ClosureAuditClassifier.php` | Create | Pure classification + actor reference |
| `src/Application/Query/GetContactTimelineQuery.php` | Modify | Allow-list, `CASE`, two `LEFT JOIN`s, `assigned_to`/`agent_id` expressions |
| `src/Application/Service/AssignmentService.php` | None | Already takes a real `string $assignedBy` |
| `src/Domain/Event/TicketEvent.php` | None | `varchar(20)` `source` unchanged (constraint 3) |
| `src/Domain/Rule/AutoCloseRule.php`, `AutoReopenRule.php` | None | Confirmed no-ops |
| `apps/api/migrations/` | None | No schema change |
| `apps/web/` | None | Not in scope |

---

## Testing Strategy

Strict TDD per `openspec/config.yaml`: failing test first, baselined against the PHPUnit failure count observed at `sdd-apply` start (**not** the stale `58` in config, and **not** zero). Frontend N/A — no frontend file in scope.

### Unit — `ClosureAuditClassifierTest` (no Doctrine, runs without Docker)

| Scenario | Expected |
|---|---|
| `('closed', null, 5)` / `('closed', null, null)` | `MANUAL_CLOSED_UNOWNED` |
| `('closed', 7, 7)` | `MANUAL_CLOSED_OWNER` |
| `('closed', 7, 3)` / `('closed', 7, null)` | `MANUAL_CLOSED_OVERRIDE` |
| The same three rows for `'resolved'` | `MANUAL_RESOLVED_*` equivalents |
| `actorReference(User#9)` | `'u:9'` |
| Every constant's length | `<= 50` (guards the `VARCHAR(50)` contract) |

### Unit — `VoterTest` (extend the existing data provider)

| Scenario | Expected |
|---|---|
| `AGENT CLOSE unassigned ticket` | DENIED (R2 — the D3 case; pin it explicitly, it is load-bearing) |
| `AGENT (no linked agent) CLOSE own ticket` | DENIED (R1) |
| `SUPERVISOR CLOSE resolved ticket` | **GRANTED, no exception** — the regression test for D1; the same case under `ASSIGN` must still throw `StateConflictException` |
| Pre-existing `ADMIN/SUPERVISOR/AGENT CLOSE` rows | Unchanged, still pass |

### Integration — authorization (per the required matrix)

For each of `POST /resolve`, `POST /close`, `POST /transition{"status":"closed"}`, `POST /transition{"status":"resolved"}`:

| Actor | Ticket | Expected |
|---|---|---|
| owning agent | owned by them | 200 |
| non-owning agent | owned by another agent | 403 + `reason: not_ticket_owner` |
| agent with no linked `Agent` | any | 403 + `reason: no_linked_agent` |
| any agent | unowned | 403 + `reason: unowned_ticket` |
| supervisor | any | 200 |
| admin | any | 200 |
| any | `POST /transition{"status":"active"}` and `{"status":"waiting"}` on another agent's ticket | **200 — not gated** (over-blocking regression) |
| any | `POST /transition` with no `status` | 400, unchanged, gate never reached |
| any | `POST /transition{"status":"CLOSED"}` (wrong case) | 422 from the FSM — confirms the gate is not bypassable |
| any | `/resolve` and `/close` on a nonexistent ticket | **400** (contract preserved, not 404) |
| denied request | — | **zero** `tickets` mutation, **zero** `ticket_events` row, **zero** `assignments` row |

### Integration — audit classification

| Scenario | Expected |
|---|---|
| Owner closes own `active` ticket via `/transition{closed}` | exactly 1 `ticket_events` row, `type=manual_closed_owner`, `source='u:{ownerUserId}'` |
| Supervisor closes another agent's `active` ticket via `/transition{closed}` | `manual_closed_override` |
| Supervisor closes an unowned ticket via `/close` (D3: always this case) | `manual_closed_unowned` |
| Owner resolves own ticket via `/resolve` | `manual_resolved_owner` |
| Supervisor resolves another agent's ticket | `manual_resolved_override` |
| Supervisor resolves an `AutoReopenRule`-reopened (therefore unowned) ticket | `manual_resolved_unowned` — pins D2's reachability claim |
| Any successful action | exactly **one** `ticket_events` row (never two) |
| Prior owner existed | resulting `assignments` row has `assigned_by = 'u:{userId}'`, **never** `'system'`; `type = UNASSIGN`; ticket `assigned_agent_id` becomes NULL (constraint 6 unchanged) |
| No prior owner | **zero** new `assignments` rows (constraint 1), but the `ticket_events` row is still written |
| `/transition{closed}` from `active` | `closed_at` is set (D7) |

### Integration — regression on the automatic paths

| Scenario | Expected |
|---|---|
| `AutoCloseRule::execute()` | exactly **one** `ticket_events` row, `type='auto_closed'`, `source='system'`; **no** `manual_closed_*` row |
| `AutoCloseRule` on an owned resolved ticket | resulting `assignments` row still `assigned_by='system'` |
| `closeDueToWindowExpiry()` | exactly one `window_expired_closed`, `source='system'`, unchanged |
| `reopenDueToInboundMessage()` | exactly one `window_reopened`, unchanged |
| `AutoReopenRule::execute()` | exactly one `auto_reopened`, `source='system'` |
| `HandleOutbound`/`HandleInboundMessageUseCase` | compile and behave unchanged with the new optional parameter; no audit row emitted |

### Integration — timeline

| Scenario | Expected |
|---|---|
| All six `manual_*` types | Appear as `ticket.manual_*`, with `assigned_to = "Admin \|Sup \|Agente {name}"` per constraint 5 |
| Admin actor who also has `ROLE_SUPERVISOR` stored | Labeled `"Admin …"`, **not** `"Sup …"` — pins the check order |
| Actor with no linked `Agent` | `assigned_to = "Admin {email}"`, `agent_id` NULL |
| Actor's `Agent` row deleted | Row still returned, `agent_id` NULL (LEFT JOIN) |
| `window_expired_closed` / `window_reopened` rows | Still returned, `assigned_to` and `agent_id` still **NULL** — zero regression from the added joins |
| `auto_closed`, `auto_reopened`, `tag_added`, `tag_removed` | Still **excluded** by the allow-list |
| Pagination | `before`/`limit`/`phone_number_id` behave identically with the widened branch |

---

## Threat Matrix

| Boundary | Applicable | Note |
|---|:--:|---|
| Routing / authorization | **Applicable** | The core of the change. Fail-closed: null `#[CurrentUser]` → 403; unknown target status → not gated but rejected by the FSM (422); denied requests write nothing. Existing `#[IsGranted('ROLE_AGENT')]` class gate retained |
| SQL injection | **Applicable** | The new timeline joins add **no** user-controlled value: `CONCAT('u:', u.id)` and the `JSON_CONTAINS` literals are constants, type names come from PHP class constants (existing convention, `GetContactTimelineQuery.php:89-90`), and `:contact_id`/`:before_date`/`:phone_number_id` remain bound parameters |
| Information disclosure | **Applicable** | The 403 body reveals only that the caller is not authorized and why (own role/ownership facts, already known to the caller). `u.email` in the timeline is internal-staff-only (D6) |
| Shell / subprocess | N/A | None |
| VCS/PR automation | N/A | None |
| Executable-file classification | N/A | None |

## Mercure Event Flow (mandated by `rules.design`)

**No payload, topic, publisher, or outbox emission is added, modified, or removed.** This change is authorization plus an append-only audit trail; there is no realtime signal in it. `resolve`/`close` emit zero outbox events today and still do.

One indirect, pre-existing emission is *unchanged* and worth naming so it is not mistaken for new: when a prior owner exists, `AssignmentService::recordOwnershipChange()` already collects `TicketUpdatedEvent` + `InboxUpdatedEvent` (`AssignmentService.php:80-99`) on every resolve/close that unassigns. This design alters only the `assignedBy` string passed to it — the event payloads are byte-identical. No store patching, no duplicated event-driven update.

## Migration / Rollout

1. Land `whatsapp-24h-window-compliance` first (`TicketEventType`, the `ticket_events` timeline branch, `idx_ticket_events_ticket_created`). Every edit here is additive on top: one constant list, one allow-list, one `CASE`, two joins.
2. Deploy backend. No migration, no schema change, no frontend deploy.
3. Rollback is a pure code revert. Orphaned `manual_*` rows stop being selected the moment the allow-list reverts — no cleanup script, no data to reconcile.

## Open Questions

- [ ] **`/close` is supervisor/admin-only in practice (D3).** `openspec/specs/authorization/spec.md`'s matrix claims agents can "close own tickets". Under this design that holds via `POST /transition {"status":"closed"}` (ticket still owned) but not via `POST /tickets/{id}/close` (every `resolved` ticket is already unowned). **`sdd-spec` must state which endpoint the requirement means**, or product must accept the split. Changing the voter to close this gap was rejected in D3 with rationale — do not "fix" it silently.
- [ ] **D7 (`closed_at` on `/transition{closed}`) is a scope-boundary call.** Included here because D3 makes that path the agent's primary close route. `sdd-tasks` may drop it as scope creep; nothing else depends on it.
- [ ] **Audit-write durability (D8).** A failed `TicketEvent` INSERT after a committed transition is logged, not retried. A future outbox-backed audit write would close this; out of scope now.
- [ ] **Sibling-change merge order.** `TicketEventType` and `GetContactTimelineQuery` are edited by both changes. Apply after `whatsapp-24h-window-compliance` is committed; both edits are purely additive, so the conflict is mechanical.
