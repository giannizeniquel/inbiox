# Proposal: Agent Reply Auto-Claim — Harden Existing Behavior

## Intent

Reply-triggered ticket assignment already ships live in `HandleOutboundMessageUseCase` (lines 163-196): it was never specified, has zero test coverage, and is documented incorrectly. Under concurrent replies it relies on optimistic-lock retry, yielding a non-deterministic owner, a wrong `previousAgent` audit chain, and phantom realtime events from rolled-back attempts. This change hardens what exists. It does not change who is assigned or when.

## Scope

### In Scope
- Serialize ownership evaluation with a pessimistic row lock on the ticket, matching `AutoAssignTicketUseCase` / `AssignTicketUseCase`.
- Clear `EventCollector` between retry attempts — `clear()` today runs only after a successful `persistEventsToOutbox()`, so a rolled-back assignment leaks a `TicketAssigned` event (and a duplicate `seq`) into the outbox.
- Skip auto-claim on `RESOLVED` / `CLOSED` tickets (confirmed real gap — see Findings).
- First test coverage for this path: claim-unassigned, reassign-to-different-replier, resolved/closed no-op, concurrent-reply serialization.
- Correct `docs/realtime/events.md:122`, which credits `AUTO_ASSIGN` to `AutoAssignTicketUseCase` only.

### Out of Scope
- WHO is auto-assigned (all roles stay eligible) and WHEN (any reply still reassigns) — user-decided, not reopened.
- Frontend changes — the Mercure pipeline already propagates ownership correctly.
- Any claim button or user-facing UI — assignment stays implicit.
- Blocking the send itself on resolved/closed tickets; this change touches assignment only.
- `TicketVoter` relaxation — the path is system-triggered by design.

## Findings — the status guard is a REAL independent gap

`ConversationWindowPolicy::isOpenForSending()` keys only on `lastInboundMessageAt` and never reads ticket status. A `RESOLVED`/`CLOSED` ticket with inbound traffic under 24h old passes the window guard, and the FSM branch has no resolved/closed case — so ownership silently changes on a closed ticket. `TicketVoter::ASSIGN` returns 409 for exactly this state. Sprint 16 Fase 3 does not cover it.

## Capabilities

### New Capabilities
- `reply-auto-assignment`: system-triggered ownership change on agent reply — eligibility, status guard, concurrency, audit trail, realtime propagation.

### Modified Capabilities
- `authorization`: clarify that the permission matrix row "Reassign tickets: ROLE_AGENT ❌" governs user-initiated assignment only, so it stops contradicting system-triggered reply auto-assignment.

## Approach

Lock first, then decide. Replace the plain read at the top of the transaction with a pessimistic-write locking read, so ownership is evaluated against a row no concurrent transaction can mutate before commit. Keep `#[ORM\Version]` and the retry loop as defense-in-depth for other writers; contention becomes a short block instead of a user-visible `ConcurrencyException`. Design owns exact mechanics: lock acquisition point, deadlock ordering, and `$em->lock()` vs a repository `FOR UPDATE` finder.

Rejected: re-checking `assignedAgentId === null` inside the retry — it contradicts the reassign semantics the user chose to keep.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` | Modified | Locking read, collector clear on retry, status guard |
| `apps/api/src/Repository/TicketRepository.php` | Modified | Locking finder, if design picks that route |
| `apps/api/tests/Integration/` | New | Dedicated test file; existing tests pass `agentId: 0` and bypass the path |
| `docs/realtime/events.md` | Modified | Document the second `AUTO_ASSIGN`/`REASSIGN` source |
| Frontend, `TicketVoter`, `AssignmentService` | Unchanged | Confirmed by exploration |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Pessimistic lock adds contention on hot tickets | Med | Lock scoped to one ticket row, held only inside the existing transaction |
| Deadlock if the ticket lock is not acquired first | Low | Ticket read is already the first statement; design fixes lock ordering |
| Tests codify undocumented behavior as intended | Med | Spec phase makes the semantics explicit and user-confirmed |
| Status guard changes live behavior | Low | No-op for the send; only suppresses the ownership change |

## Rollback Plan

Single revert of the use-case commit. No schema migration, no data backfill, no API contract or frontend change. The docs correction reverts independently.

## Dependencies

None. Sprint 16 Fase 3 window-compliance work is already merged.

## Success Criteria

- [ ] Concurrent replies to one ticket produce a deterministic single owner with an accurate `previousAgent` chain
- [ ] No outbox/Mercure event is emitted for a rolled-back attempt
- [ ] A reply to a `RESOLVED`/`CLOSED` ticket changes no ownership
- [ ] The `agentId > 0` path has integration coverage (previously zero)
- [ ] No `ConcurrencyException` surfaces for three or fewer concurrent replies
- [ ] `docs/realtime/events.md` names both `AUTO_ASSIGN` sources

## Proposal question round

Interactive mode: three product decisions surfaced during investigation that were not covered by the two already-resolved decisions. Current assumptions are stated; confirm or correct.

1. **Concurrency winner semantics.** The task framing said the intent is "first reply wins", but keeping "any reply reassigns" means sequential replies already produce last-reply-wins ownership. Making concurrent replies first-wins would contradict it. Assumption: the rule is **last committed reply owns, serialized deterministically**, with a correct audit chain and no phantom events. Confirm?
2. **Resolved/closed tickets.** Assumption: skip the ownership change silently and still deliver the message. Alternative: reject the send with 409, matching `TicketVoter::ASSIGN` — a larger behavior change affecting messaging, not just assignment.
3. **Phantom-event fix.** Assumption: the `EventCollector` retry leak is in scope as part of "fix the race". It is a separate defect on the same path and could be split out.
