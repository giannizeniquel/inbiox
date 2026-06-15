# Sprint 10 — Agent Management: Change Proposal

## Intent

Replace hardcoded `active AND is_available` with deterministic, timezone-aware availability. Today agents are "always on" — no shifts, no AFK, unreliable capacity counter. Sprint 10 fixes this.

## Scope

**In:** `Agent` +4 fields (timezone, override, override_until, current_active_tickets). `AgentShift` entity (weekly DoW, cross-midnight). `AgentAvailabilityService` (override→schedule→capacity). `AutoAssignTicketUseCase` rewrite: service + `SELECT FOR UPDATE` + cached counter + **Assignment row**. `RebalanceUnassignedTicketsUseCase`. 4 endpoints: POST/DELETE availability, POST/DELETE shifts. 3 Mercure events: availability_changed, shift_changed, ticket.assigned. Migration + backfill. Reports: real names + real load. 12 tests.

**Out:** Presence states, skill routing, per-number rules, Teams, SLA escalation, internal notes, User/Auth, capacity bar UI, reassign with note, Owner+Participants.

## Capabilities

- **New `agent-availability`**: Deterministic evaluation: TTL override → schedule (timezone-aware, cross-midnight) → capacity.
- **New `auto-assignment-audit`**: Auto-assign writes Assignment + increments counter in same transaction.
- **Modified**: None.

## Approach

Extend proven pattern already in `AutoAssignTicketUseCase`: `SELECT FOR UPDATE` + transactional counter. Inject `AgentAvailabilityService` as single source of truth. Force auto-assign to write `Assignment` row matching `AssignTicketUseCase` behavior. `Assignment.agentId` migrated to `BIGINT` with FK to `agents.id` (table was empty — zero-risk migration).

## Product Decisions (Resolved)

| # | Decision |
|---|----------|
| Q1 | Auto-assign writes Assignment row (audit consistency) |
| Q2 | Owner+Participants deferred (MVP = 1 owner) |
| Q3 | Sprint 10.5 planned (capacity bar + reassign with note) |
| Q4 | Presence states OUT (TTL override = simpler MVP) |

## Chained PR Plan (~1300 lines, 5 slices)

| PR | Scope | Lines |
|----|-------|-------|
| 1 | Agent entity + AgentShift + migration + backfill | ~250 |
| 2 | AgentAvailabilityService + 12 tests | ~400 |
| 3 | AutoAssign rewrite + Rebalance use case | ~250 |
| 4 | Endpoints + Mercure events | ~250 |
| 5 | Reports fix + events.md | ~150 |

## Rollback

Per PR: (1) revert migration, drop table; (2) delete service; (3) revert use case; (4) delete controllers; (5) revert query.

## Risks

| Risk | Mitigation |
|------|------------|
| ~~`Assignment.agentId` string vs `Agent.id` int~~ | Resolved: migrated to BIGINT with FK in PR1 (table was empty — 0 rows) |
| Backfill locks tickets on large datasets | Chunked UPDATE |
| Mercure topic auth unauthenticated | Document, defer to auth sprint |
| Override null TTL = persistent (UX risk) | UI warning post-MVP |
| Cross-midnight shift evaluation bug-prone | 4 dedicated test cases |

## Success Criteria

- [ ] 12/12 availability scenarios pass
- [ ] No over-allocation under 50 concurrent assigns
- [ ] Auto-assign writes Assignment row in transaction
- [ ] Backfill correct on 10k-ticket dataset
- [ ] Leaderboard shows real names + real load
- [ ] events.md documents new Mercure events

## Post-MVP

| Priority | Capability | Target |
|----------|-----------|--------|
| P1 | Capacity bar + reassign with note | Sprint 10.5 |
| P2 | SLA escalation + internal notes | Sprint 11-12 |
| P3 | Presence, Teams, per-number pools | Sprint 13-15 |
| P4 | Skill routing, SLA auto-escalation, Auth | Sprint 16+ |
