# Exploration: Ticket Closure Authorization and Audit Trail

**Change:** `ticket-closure-authorization` (Sprint 16 Fase 1)
**Date:** 2026-08-29
**Source:** `docs/04_sprints/sprint_16 (Production Readiness).md`, "Fase 1 — Autorización y trazabilidad de cierre/resolución" section

## Business rule

Only `ROLE_ADMIN`, `ROLE_SUPERVISOR`, or the ticket's current owning `ROLE_AGENT` may resolve or close a ticket. Every such action — with or without a prior owner — must leave a real audit record identifying who did it. A window-expiry close (already implemented in `whatsapp-24h-window-compliance`) must stay attributed to `system`, distinct from a human close.

## Current state (verified against code, 2026-08-29)

### 1.1 — Authorization gap: confirmed exactly as scoped

`TicketWorkflowController` (`apps/api/src/Controller/TicketWorkflowController.php`) has only a class-level `#[IsGranted('ROLE_AGENT')]`. `resolve()`, `close()`, `transition()` (lines 24-83) perform **zero** per-ticket authorization — no `#[CurrentUser]`, no voter call anywhere in the class.

Two authorization patterns already exist in this codebase as precedent:
- `TicketController::assign()` (`apps/api/src/Controller/TicketController.php:29-44`) — manual `Security::isGranted('ASSIGN', $ticket)` + a hand-built 403 `JsonResponse`. Doesn't extend `AbstractController`; uses a raw `int $id` route param. **This is the closer structural match** to `TicketWorkflowController`'s current shape.
- `MediaUrlController::__invoke()` (`apps/api/src/Controller/MediaUrlController.php:30`) — `#[IsGranted('ACCESS', subject: 'message')]` with an entity-typed route param. Doesn't fit `TicketWorkflowController`'s raw-`int` signatures without a bigger refactor of those actions.

`TicketVoter` (`apps/api/src/Application/Security/Voter/TicketVoter.php`) already declares `VIEW`/`EDIT`/`ASSIGN`/`CLOSE` constants, but **only `ASSIGN` is ever invoked anywhere** (confirmed via full-repo grep). `ASSIGN`'s existing logic — SUPERVISOR+ always allowed (role hierarchy makes `ROLE_ADMIN` include `ROLE_SUPERVISOR`, confirmed in `security.yaml:2-4`), `ROLE_AGENT` allowed only if `ticket.assignedAgentId === user.agent.id` — is nearly exactly the rule this change needs. `CLOSE` is declared but unused and can be wired up (or `ASSIGN` reused for both actions) — a proposal-phase naming/reuse decision, not a blocker.

### 1.2 — Audit trail gap: more precise than the sprint doc's wording

**Verified**: the `'system'` literal is not inside `AssignmentService::recordOwnershipChange()` — that method already accepts a required `string $assignedBy` parameter (`apps/api/src/Application/Service/AssignmentService.php:47-54`) and stores it on `Assignment.assignedBy` (`varchar(100)` — plenty of room for a real identity). The hardcoding is at the **call site**: `TransitionTicketStatusUseCase::recordUnassignIfOwner()`, line 59 (`assignedBy: 'system'`).

The "early-return when there is no prior owner" the sprint doc describes is actually **three** silent-return points inside `recordUnassignIfOwner()` (`apps/api/src/Application/UseCase/TransitionTicketStatusUseCase.php:37-61`), not one:
```php
private function recordUnassignIfOwner(int $ticketId): void
{
    $ticketEntity = $this->ticketRepo->findById($ticketId);
    if ($ticketEntity === null) { return; }          // L40-42

    $ownerId = $ticketEntity->getAssignedAgentId();
    if ($ownerId === null) { return; }                // L45-47 — "no prior owner"

    $agent = $this->agentRepository->findById($ownerId);
    if ($agent === null) { return; }                  // L50-52

    $this->assignmentService->recordOwnershipChange(..., assignedBy: 'system', ...);  // L54-60
}
```
Plus **call-site guards** before this method is even reached (verified at lines 95-97, 161, 278): `if (!empty($ticket['assigned_agent_id'])) { $this->recordUnassignIfOwner($ticketId); ... }`. All of these must change for "always leave a record, with or without a prior owner" to hold — not just the innermost early-return.

### 1.3 — Distinguishing human vs. automatic close: more coupled than the sprint doc implies

**This is the exploration's most consequential finding.** `TransitionTicketStatusUseCase::close()` is shared by **three** callers, not the two the sprint doc's framing suggests:

1. `TicketWorkflowController::close()` — human, `POST /tickets/{id}/close`.
2. **`Domain/Rule/AutoCloseRule::execute()`** — a pre-existing, **unrelated** automatic rule (closes `resolved` tickets 24h after `updatedAt`, triggerable via `AutoCloseController`, unrelated to the WhatsApp window). Verified in full:
   ```php
   public function execute(RuleContext $context): void
   {
       $this->transitionUseCase->close($context->getTicketId());   // L49

       // Emit auto_closed event
       $event = new TicketEvent($context->getTicketId(), 'auto_closed', 'system');  // L52
       $this->em->persist($event);
       $this->em->flush();
   }
   ```
   The **Rule layer**, not the use case, owns event emission today. `AutoReopenRule::execute()` follows the identical pattern for `'auto_reopened'` (calls `execute()`, then separately emits its own `TicketEvent`).
3. `whatsapp-24h-window-compliance`'s `closeDueToWindowExpiry()` — a **separate method** that deviates from that convention by writing its `TicketEvent` **inside itself**, not from a calling Rule.

**Consequence, verified not hypothetical**: if this change adds "write a human-close `TicketEvent`" **unconditionally inside** `close()`, every `AutoCloseRule`-triggered close would ALSO get a spurious "human closed" event alongside (or instead of, depending on ordering) `AutoCloseRule`'s own correct `'auto_closed'` event — misattributing or double-emitting for a real, currently-shipping automatic close path.

**Recommendation**: keep `close()`/`resolve()`/`execute()` event-free, exactly as today. Have `TicketWorkflowController` emit the manual-close/resolve `TicketEvent` itself, after a successful call — mirroring the `AutoCloseRule`/`AutoReopenRule` convention (2 of 3 existing callers already emit from the calling layer, not from inside the shared use case). This avoids touching the shared method's internals at all for event-writing purposes; only its authorization/actor-threading needs to change (1.1/1.2).

Also verified: `execute()` (the underlying generic transition method) has two more automatic callers unrelated to closing — `HandleOutboundMessageUseCase.php:233` and `HandleInboundMessageUseCase.php:323` — neither ever targets `resolved`/`closed`. Any signature change to `execute()`/`close()`/`resolve()` (e.g. adding an actor parameter) must default safely for these, not require every caller to pass one.

### 1.4 — Timeline extension: clean and additive

`GetContactTimelineQuery::buildSql()` (`apps/api/src/Application/Query/GetContactTimelineQuery.php:67-154`) already has the Fase-3 `ticket_events` `UNION ALL` branch with an explicit allow-list (line 75) and a `CASE` mapping (lines 136-139). Adding `MANUAL_CLOSED`/`MANUAL_RESOLVED` to `TicketEventType` (currently only `WINDOW_EXPIRED_CLOSED`/`WINDOW_REOPENED`) and extending both the allow-list and the `CASE` is a same-shape, additive change. `TimelineEventDTO` already has generic `assignedTo`/`agentId` fields reusable for the new event type — **no DTO schema change needed**.

**Schema concern surfaced, not assumed**: `TicketEvent.source` is `varchar(20)`. No code today actually stores a real actor identity there. The candidate precedent named in the brief, `AddTagToTicketUseCase`, is **not actually a working precedent** — verified its sole caller (`TagController.php`) always passes the literal string `'manual'`, never a real actor reference. If the design wants `source` to carry a real actor identity, `varchar(20)` is workable only for a compact reference (a numeric agent/user ID as a string); an email or longer identifier needs either a wider column or resolving a display name via join at read time — exactly as the existing `assignments` branch already does with `LEFT JOIN agents`. This is an open decision, not a given.

## No unexpected interaction with `whatsapp-24h-window-compliance` — confirmed additive-only, except one real coupling

The `ticket_events` table, its new index (`idx_ticket_events_ticket_created`), and the timeline's `UNION ALL` branch are all extensible by adding allow-list entries — nothing needs restructuring. The **one real coupling** is the shared `close()` method (see 1.3 above), which needs a deliberate design decision (event-free, caller emits), not a blind additive patch that risks `AutoCloseRule` misattribution.

## Reports / `OwnershipScope` unaffected — confirmed

`OwnershipScope::getAgentId()` still always returns `null` (Fase 0 decision, commit `3a1509c`, untouched by this exploration). `ReportVoter` remains dead/unwired, fully separate from `TicketVoter`. Nothing in this change's scope touches `ReportingController` — reports stay unrestricted by agent, per the sprint doc's confirmed business model.

## Affected areas

| Area | Impact |
|---|---|
| `TicketWorkflowController.php` | Add `#[CurrentUser]` + per-action authorization (1.1); emit the manual-close/resolve `TicketEvent` after success (1.3) |
| `TicketVoter.php` | Wire up `CLOSE` (or repurpose `ASSIGN`) for resolve/close |
| `TransitionTicketStatusUseCase.php` | `recordUnassignIfOwner()` — real actor, remove the three early-returns and the call-site guards (1.2). `close()`/`resolve()`/`execute()` internals stay event-free (1.3) |
| `Domain/Rule/AutoCloseRule.php` | Must remain correctly attributed to `system` — verify no regression once `close()`'s caller-side changes land |
| `TicketEventType.php` | Add `MANUAL_CLOSED`/`MANUAL_RESOLVED` |
| `TicketEvent.php` | `source` column-width decision (varchar(20) vs. wider vs. join-at-read-time) |
| `GetContactTimelineQuery.php` | Extend allow-list (L75) + `CASE` (L136-139) |
| Tests | Zero existing coverage for: audit-writing paths, the no-prior-owner case, or `TicketWorkflowController` authorization. All net-new. |

## Open decisions for `sdd-propose`

1. **No-prior-owner close/resolve**: an owner-less `Assignment` row as the audit source of truth, or `ticket_events`-only?
2. **Where event-writing responsibility lives**: confirmed recommendation is caller-level (`TicketWorkflowController` emits after success), matching `AutoCloseRule`/`AutoReopenRule`'s existing convention — vs. threading an `?actor` parameter into `close()`/`resolve()`/`execute()` itself (rejected in this exploration due to the verified `AutoCloseRule` misattribution risk, but flagged for the user to confirm rather than decided unilaterally).
3. **`TicketEvent.source` width**: widen the column, or keep it compact and resolve a display name via join at read time (matching the existing `assignments` branch's `LEFT JOIN agents` pattern)?
4. **403 response shape**: match `TicketController::assign()`'s existing `{'error': 'Forbidden'}` shape, or something resolve/close-specific?
5. **Exact audit wording**: "Supervisor X closed…" / "Agent Y closed…" — how should an ADMIN actor be labeled, given `ROLE_ADMIN` inherits `ROLE_SUPERVISOR` via the role hierarchy (so an admin acting is also, technically, a supervisor)?

## Recommendation

Treat the sprint doc's 4-item breakdown (1.1-1.4) as structurally correct, but design 1.2 and 1.3 as **one unified change** (spanning `Assignment` audit + `ticket_events` audit) rather than independent patches — specifically because of the verified `AutoCloseRule` coupling. Approach: caller-level event emission (`TicketWorkflowController` writes the audit event after a successful resolve/close), keeping the shared `close()`/`resolve()`/`execute()` methods event-free exactly as they are today. This is lower-risk than threading an actor parameter through those methods, matches the existing convention 2 of 3 current callers already follow, and required zero changes to `AutoCloseRule`/`AutoReopenRule` themselves.
