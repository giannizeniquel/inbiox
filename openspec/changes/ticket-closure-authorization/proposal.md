# Proposal: Ticket Closure Authorization and Audit Trail

## Intent

Today **any** authenticated `ROLE_AGENT` can resolve or close **any** ticket. `TicketWorkflowController` carries only a class-level `#[IsGranted('ROLE_AGENT')]`; `resolve()`, `close()` and `transition()` perform zero per-ticket authorization (verified, `TicketWorkflowController.php:24-83`). The `openspec/specs/authorization/spec.md` requirement "Agent cannot close another agent's ticket → 403" is **specified but not enforced** — a live spec/code divergence.

The audit trail is equally unreliable: the only record a close leaves is an `Assignment` row written with a hardcoded `assignedBy: 'system'` (`TransitionTicketStatusUseCase.php:59`), and only when the ticket had a prior owner — three silent early-returns plus call-site guards suppress it otherwise. So a human close is indistinguishable from `AutoCloseRule`'s automatic close, and a close on an unowned ticket leaves no trace at all.

Grounded by `exploration.md`; its five closed decisions are **fixed constraints** here, not options.

## Fixed Constraints (from exploration, do not re-litigate)

1. No-prior-owner close/resolve is **`ticket_events`-only**. `recordUnassignIfOwner()` keeps firing (and writing to `assignments`) only when a real prior owner existed. `Assignment`'s meaning is not stretched.
2. Event emission is **caller-level**. `close()`/`resolve()`/`execute()` stay event-free; `TicketWorkflowController` emits after success — matching `AutoCloseRule`/`AutoReopenRule`. Emitting from inside `close()` would misattribute or double-emit for every `AutoCloseRule` close, which shares that method.
3. `TicketEvent.source` stays `varchar(20)`. Store a compact agent/user ID; resolve display name/role by **join at read time**.
4. The 403 body is **custom to this action**, not `TicketController::assign()`'s generic `{'error': 'Forbidden'}`.
5. Actor label: `ROLE_ADMIN` → **"Admin"**; `ROLE_SUPERVISOR` without `ROLE_ADMIN` → **"Sup"**; `ROLE_AGENT` → **"Agente"**. Check `ROLE_ADMIN` **first** — role hierarchy makes every admin also a supervisor.

## Scope

### In Scope

- **1.1 Authorization** — `POST /tickets/{id}/resolve`, `/close`, and `/transition` **when the target status is `resolved` or `closed`** are restricted to `ROLE_ADMIN`, `ROLE_SUPERVISOR`, or the ticket's current owning `ROLE_AGENT`. Reuses the **existing `TicketVoter`**, whose `ASSIGN` rule (SUPERVISOR+ always; AGENT only if `ticket.assignedAgentId === user.agent.id`) is already almost exactly this rule. Structural pattern: `TicketController::assign()`'s manual `Security::isGranted()` + hand-built `JsonResponse` — **not** `MediaUrlController`'s `#[IsGranted(subject:)]`, which needs entity-typed route params these raw `int $id` actions do not have.
- **1.2 Real actor in the `assignments` audit trail** — replace the hardcoded `'system'` at the `recordUnassignIfOwner()` **call site** with the real actor threaded from the controller. `AssignmentService::recordOwnershipChange()` is untouched — it already takes a real `string $assignedBy`.
- **1.3 Human vs. automatic close** — add `MANUAL_CLOSED`/`MANUAL_RESOLVED` to `TicketEventType`; `TicketWorkflowController` writes the row after a successful action, **always**, owner or not. `close()`/`resolve()`/`execute()` internals are not touched.
- **1.4 Timeline** — extend `GetContactTimelineQuery`'s existing `ticket_events` allow-list (today `window_expired_closed`/`window_reopened`) and its `CASE` mapping with the two new types, plus a join to resolve the actor's display name and label (constraint 5), reusing the **existing `LEFT JOIN agents` shape** already present in the `assignments` branch.

### Confirmed no-ops (verified, stated so they are not "fixed" by mistake)

- `AutoCloseRule` and `AutoReopenRule` need **zero** changes — they already emit `auto_closed`/`auto_reopened` correctly from the Rule layer.
- `closeDueToWindowExpiry()` (sibling change) needs **zero** changes — separate method, own emission, already isolated and attributed to `system`.
- `AssignmentService`, `TicketEvent` (column widths), `OwnershipScope`, `ReportVoter`, `ReportingController`, `TimelineEventDTO` — unchanged.

### Out of Scope

- Any frontend change. No frontend file is touched (per exploration's affected-areas table).
- Any migration or schema change (constraint 3).
- A `ticket_events.reason` / free-text close-reason column, and close-reason UI.
- Re-litigating the five closed decisions.
- Refactoring the legacy raw-string event types (`auto_closed`, `tag_added`, …) onto `TicketEventType`.
- Making the ticket list update live on a manual close (no Mercure signal exists for status changes — a known pre-existing limitation carried over from the sibling change).

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `authorization`: the "Agent can/cannot close own ticket" scenarios become **enforced**, extended from `/transition` only to also cover `/resolve` and `/close`, and enforced by a per-ticket **voter**, not by an `#[IsGranted]` role attribute as the current requirement text states. Requires a delta spec.
- `ticket-closure-audit`: extend with manual close/resolve events and actor attribution. **Note for `sdd-spec`**: this capability is introduced by the sibling `whatsapp-24h-window-compliance` change and does **not** yet exist under `openspec/specs/` — extend that pending capability, do not create a duplicate new spec.

## Approach

1. Wire a per-ticket authorization check into all three `TicketWorkflowController` actions (`/transition` conditionally, on target status), returning the custom 403 shape.
2. Thread the authenticated actor from the controller into `TransitionTicketStatusUseCase`, replacing `'system'` at the `recordUnassignIfOwner()` call site while leaving that method's owner-only firing condition intact.
3. Add the two `TicketEventType` constants; the controller persists a `TicketEvent(type, actorId)` after every successful manual resolve/close.
4. Extend the timeline query's allow-list, `CASE`, and actor join; apply the Admin/Sup/Agente labeling with `ROLE_ADMIN` checked first.

## Mercure Event Contract

**Untouched.** This change introduces no new, modified, or removed Mercure payload, topic, or publisher, and adds no outbox emission. It is authorization plus an audit trail — there is no realtime signal in it at all. (Resolve/close already emit zero outbox events today; that remains true.)

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Controller/TicketWorkflowController.php` | Modified | `#[CurrentUser]` + per-action authorization; custom 403; emits `MANUAL_CLOSED`/`MANUAL_RESOLVED` after success |
| `src/Application/Security/Voter/TicketVoter.php` | Modified | Wire the closure rule (see Open Item) |
| `src/Application/UseCase/TransitionTicketStatusUseCase.php` | Modified | Real actor at the `recordUnassignIfOwner()` call site; `close()`/`resolve()`/`execute()` internals unchanged |
| `src/Domain/Event/TicketEventType.php` | Modified | `MANUAL_CLOSED`, `MANUAL_RESOLVED` |
| `src/Application/Query/GetContactTimelineQuery.php` | Modified | Allow-list + `CASE` + actor join and labeling |
| `apps/api/tests/` | New | **All net-new** — see Testing Constraints |
| `apps/api/migrations/` | None | No schema change |
| `apps/web/` | None | Not in scope |

## Risks

| Risk | Likelihood | Mitigation |
|------|:---:|------------|
| Actor parameter added to `close()`/`resolve()`/`execute()` breaks the automatic callers (`AutoCloseRule`, `HandleOutbound/InboundMessageUseCase`, `closeDueToWindowExpiry`) | Med | Constraint 2: no event emission moves into those methods. Any actor parameter MUST default safely so automatic callers stay valid unchanged; regression test asserts `AutoCloseRule` still produces exactly one `auto_closed` event attributed to `system` |
| Removing the `recordUnassignIfOwner()` call-site guards writes owner-less `Assignment` rows | Med | Constraint 1 forbids it: the no-owner audit lives in `ticket_events`. The guards and early-returns stay; only the hardcoded `'system'` changes |
| `/transition` gate misses a path, or over-blocks a legitimate non-closing transition (`new→active`, `active→waiting`) | Med | Gate on **target status only** (`resolved`/`closed`); explicit tests for allowed and denied targets |
| Timeline actor join returns no agent (deleted agent, or `source` holding a non-agent user id) | Med | `LEFT JOIN`, exactly as the existing `assignments` branch; the row still renders with a null actor rather than disappearing |
| Sibling change is implemented but uncommitted on the same branch — merge/rebase collision in `TicketEventType` and `GetContactTimelineQuery` | Med | Both edits are purely additive (one constant list, one allow-list, one `CASE`); apply after the sibling is committed |
| Enforcing authorization is a behavior change for existing clients that relied on the gap | Low | The `authorization` spec already declares this as the intended rule; no client currently depends on cross-agent closing by design |

## Rollback Plan

**Pure code revert.** This change touches **no schema** — no migration is added, and `TicketEvent.source` keeps its `varchar(20)` width (constraint 3). Reverting the commits restores the previous behavior exactly; there is nothing to migrate down and no data to reconcile.

`ticket_events` rows of type `manual_closed`/`manual_resolved` written before the revert become orphaned types — harmless: `GetContactTimelineQuery`'s allow-list reverts alongside, so those rows are simply no longer selected (the same forward-compatible allow-list property the sibling change relies on). No cleanup script needed.

## Dependencies

- `whatsapp-24h-window-compliance` must land first on `sprint-16-production-readiness`: `TicketEventType`, the `ticket_events` timeline branch, and `idx_ticket_events_ticket_created` all come from it. This change is strictly additive on top.
- `TicketVoter` and `security.yaml`'s role hierarchy (`ROLE_ADMIN > ROLE_SUPERVISOR > ROLE_AGENT`) already exist and are unchanged.

## Testing Constraints

Per `openspec/config.yaml`: backend **strict TDD** (failing test first), baselined against the pre-existing PHPUnit failure count — **not** against a green suite, and **not** against the stale `58` recorded in config. `sdd-apply` MUST observe the actual baseline at its own start (the sibling change's apply may have shifted it) and must not exceed it.

**Frontend testing is not applicable** — no frontend file is in scope.

**Scope note on effort**: exploration confirmed **zero existing test coverage** for the audit-writing paths, the no-prior-owner case, and `TicketWorkflowController` authorization. This is substantially new test surface, not incremental edits to existing tests — `sdd-tasks` should size it against the 800-line review budget accordingly.

## Open Item for `sdd-design`

**Wire `TicketVoter::CLOSE`, or reuse `TicketVoter::ASSIGN`?** `TicketVoter` declares `VIEW`/`EDIT`/`ASSIGN`/`CLOSE` but only `ASSIGN` is invoked anywhere in the repo (full-repo grep, exploration). `ASSIGN`'s rule is nearly exactly what closure needs, so both are viable:

- **Wire `CLOSE`** — semantically honest, leaves `ASSIGN`'s rule free to diverge later (e.g. if reassignment becomes supervisor-only); costs a small duplicated rule body today.
- **Reuse `ASSIGN`** — zero new logic, but couples two permissions that the RBAC matrix already treats differently ("Reassign tickets" is ❌ for agents, "Close own tickets" is ✅).

Exploration flagged this and deliberately did not resolve it. **`sdd-design` decides.** Not decided here.

**Additional data point, verified 2026-08-29**: `tests/Security/VoterTest.php` already contains test cases for the `CLOSE` attribute ("ADMIN CLOSE other ticket", "SUPERVISOR CLOSE other ticket", "AGENT CLOSE own ticket") even though no controller invokes it today — `CLOSE` falls through `voteOnAttribute()`'s same generic ADMIN/SUPERVISOR-always / AGENT-only-if-owner logic as `ASSIGN` (the only branch that differs by attribute is the `ASSIGN`-specific resolved/closed state guard). This means wiring `CLOSE` up is lower-risk than "new logic" — its authorization behavior is already exercised in isolation; only the controller call site (`denyAccessUnlessGranted(TicketVoter::CLOSE, $ticket)` or equivalent) would be new. Does not resolve the semantic-coupling tradeoff above; just narrows the effort/risk delta between the two options.

## Success Criteria

- [ ] `POST /tickets/{id}/resolve` and `/close` return the custom 403 for a `ROLE_AGENT` who is not the ticket's owner, and 200 for the owner, a supervisor, or an admin.
- [ ] `POST /tickets/{id}/transition` is gated identically **only** when the target status is `resolved` or `closed`; every other transition behaves exactly as today.
- [ ] Every successful manual resolve/close writes exactly one `ticket_events` row of type `manual_resolved`/`manual_closed` carrying the real actor — **including when the ticket had no prior owner**.
- [ ] When a prior owner existed, the resulting `assignments` row records the real actor, never the literal `'system'`.
- [ ] `AutoCloseRule` still writes exactly one `auto_closed` event attributed to `system`, with no additional manual-close event.
- [ ] `closeDueToWindowExpiry()` still writes exactly one `window_expired_closed` event attributed to `system`, unchanged.
- [ ] The contact timeline shows manual close/resolve entries with the actor labeled `Admin` / `Sup` / `Agente`, resolved by join (no `TicketEvent.source` width change).
- [ ] No migration is added; `git diff` shows zero files under `apps/api/migrations/` and zero under `apps/web/`.
- [ ] No Mercure payload, topic, publisher, or outbox emission is added or changed.
- [ ] PHPUnit failure count at or below the baseline observed at apply start.
