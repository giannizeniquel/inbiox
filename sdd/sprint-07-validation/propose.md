# Proposal: Sprint 07 Validation — Idempotency, Concurrency, AutoClose, SLA Integrity

## Intent

Sprint 7 implemented FSM + SLA timestamps, but four production-critical gaps were found during validation:
no event-level idempotency (reprocessing duplicates messages and re-runs transitions), no optimistic
locking on tickets (race conditions on simultaneous inbound/outbound), AutoClose doesn't distinguish
customer vs agent messages after resolve, and no integration tests verify SLA determinism.

## Scope

### In Scope
- Add `version` column to Ticket entity for Doctrine optimistic locking
- Add event key tracking to `HandleOutboundMessageUseCase` to prevent duplicate processing
- Fix `AutoCloseTicketUseCase` to check for **customer** messages after `resolved_at`
- Write PHPUnit integration tests covering idempotency, concurrency, SLA integrity, and AutoClose
- Verify `waiting` state is correctly counted as active in Assignment (confirm-only, no code change)

### Out of Scope
- Pessimistic locking (already covered by `SELECT ... FOR UPDATE` in transitions)
- SLA breach alerts or priority-based SLA (Sprint 8+)
- Reopen logic (Sprint 8+)
- Async queue infrastructure

## Capabilities

### New Capabilities
- `idempotent-events`: Event key tracking prevents duplicate processing of the same outbound event
- `optimistic-locking`: Doctrine version column protects Ticket from concurrent modifications
- `autoclose-activity-check`: AutoClose verifies no customer messages exist after resolved_at

### Modified Capabilities
- `ticket-workflow`: Idempotency and optimistic locking modify how transitions are guarded
- `sla-tracking`: Integration tests validate FRT/RT determinism end-to-end

## Approach

| Gap | Solution | Detail |
|-----|----------|--------|
| **Idempotency** | Event key per outbound operation | `HandleOutboundMessageUseCase` computes a deterministic key (`outbound:{ticketId}:{contentHash}`) and stores it. On reprocess, if key exists, return early with existing result. Stored in a new `ticket_event_log` table (not on Ticket itself to keep entity clean). |
| **Concurrency** | Doctrine `#[Version]` on Ticket | Add `int $version` with `#[ORM\Version]`. Doctrine throws `OptimisticLockException` on stale flush. Wrap in transaction with retry (max 3). |
| **AutoClose** | Customer-only activity check | Replace raw SQL in `execute()` with a cleaner query: only close if no inbound messages exist after `resolved_at`. Keep `executeSimple()` but fix direction filter. |
| **SLA Tests** | PHPUnit integration tests | 5 test cases: FRT set once, FRT unchanged on reprocess, RT after multiple waiting cycles, idempotency skips transition, AutoClose ignores agent-only messages. |
| **Assignment** | Verify-only | Confirm `waiting` counts in active ticket query. No code change needed. |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Domain/Ticket/Ticket.php` | Modified | Add `#[ORM\Version]` private int `$version = 0` |
| `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` | Modified | Add event key generation and early-return on duplicate |
| `apps/api/src/Application/UseCase/AutoCloseTicketUseCase.php` | Modified | Fix `execute()` SQL to filter by `direction = 'inbound'` |
| `apps/api/src/Domain/Ticket/TicketEventLog.php` | New | Entity tracking processed event keys |
| `apps/api/tests/Integration/IdempotencyTest.php` | New | Idempotency + event key tests |
| `apps/api/tests/Integration/ConcurrencyTest.php` | New | Optimistic locking + retry tests |
| `apps/api/tests/Integration/SLAIntegrityTest.php` | New | FRT/RT determinism across lifecycle |
| `apps/api/tests/Integration/AutoCloseTest.php` | New | Activity-check after resolved_at |
| `apps/api/migrations/Version*.php` | New | Migration for `version` column and `ticket_event_log` table |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Migration locks tickets table on prod | Low | Run during low-traffic window; `version` column has default 0 |
| Event key hash collision | Very Low | Use content hash + ticketId + event type; collision astronomically unlikely |
| Retry loop on high-concurrency | Medium | Cap at 3 retries with exponential backoff; fail fast with clear error |
| AutoClose SQL still incorrect | Low | Tests validate both positive and negative cases |

## Rollback Plan

1. Revert the migration (drop `version` column and `ticket_event_log` table)
2. Restore previous versions of `HandleOutboundMessageUseCase` and `AutoCloseTicketUseCase`
3. Delete new test files
4. No data loss: `version` column is additive, event log is append-only

## Dependencies

- Sprint 7 codebase (FSM + SLA timestamps) already exists
- MySQL `TIMESTAMPDIFF` support (already in use)
- Doctrine ORM with optimistic locking support (built-in)

## Success Criteria

- [ ] Reprocessing the same outbound event produces no duplicate messages or state changes
- [ ] Simultaneous inbound + outbound on same ticket throws `OptimisticLockException` (caught, retried)
- [ ] AutoClose skips tickets with customer messages after `resolved_at`
- [ ] FRT timestamp is set exactly once and never modified (verified by test)
- [ ] RT reflects actual resolved_at after multiple waiting→active cycles (verified by test)
- [ ] All 5 test suites pass with 100% assertion coverage
