# Tasks: Agent Reply Auto-Claim — Harden Existing Behavior

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines — core logic | ~135 (TicketRepository +20, HandleOutboundMessageUseCase ~90 across 3 edits, ProviderProxyController ~25) |
| Estimated changed lines — non-concurrency tests | ~450–550 (claim/reassign/no-op, collector-leak proof, resolved/closed x3, guard-truth-table unit, HTTP 409) |
| Estimated changed lines — concurrency-proof harness | ~300–350 (lock-blocking 2nd-connection test ~100 + pcntl_fork two-kernel last-commit-wins test ~220, flagged separately as historically underestimated) |
| Estimated changed lines — docs | ~15 |
| **Total estimated** | **~900–1050** |
| 400-line budget risk | High |
| 800-line budget risk (session cache) | High — midpoint estimate already exceeds it |
| Chained PRs recommended | **Yes** |
| Suggested split | 3 work units — see below |
| Delivery strategy (cached) | single-pr |
| Chain strategy | **pending — user decision required** (stacked-to-main / feature-branch-chain / size-exception) |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

**Do not silently ship as size:exception.** Present this split to the user before `sdd-apply`, same as `contact-csv-import` and `reports-csv-export` this session. If the user still prefers a single PR, `size:exception` requires explicit maintainer acceptance given the estimate exceeds both the 400-line default and the 800-line session budget.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Locking finder + serialized ownership decision (D1/D2) + both concurrency proofs + first-ever claim/reassign/no-op coverage | PR 1 | `docker compose exec php bin/phpunit tests/Integration/AgentReplyAutoClaimConcurrencyTest.php tests/Integration/AgentReplyAutoClaimTest.php --filter "Claim|Reassign|NoOp"` | Real: (a) 2nd DBAL connection holds `FOR UPDATE` + `innodb_lock_wait_timeout=1`; (b) `pcntl_fork` two child processes, each booting a real kernel and driving the actual use case | Revert `TicketRepository::findByIdForUpdate()` + the `findById()`+`lock()` swap; no schema/API contract change |
| 2 | EventCollector leak fix (D6) — clear-on-abort + outbox flush moved out of `try` | PR 2 | `docker compose exec php bin/phpunit tests/Integration/AgentReplyAutoClaimTest.php --filter CollectorLeak` | Real: 2nd connection bumps `ticket.version` mid-transaction to force `OptimisticLockException` on attempt #1 | Revert the flush-position + clear-on-abort diff only; independent of Unit 1's lock swap |
| 3 | Resolved/closed ownership guard (D3/D4/D5) + HTTP 409 wiring + docs correction | PR 3 | `docker compose exec php bin/phpunit tests/Integration/AgentReplyAutoClaimTest.php --filter ResolvedClosed tests/Integration/ProviderProxyControllerAutoClaimTest.php` | Real: `WebTestCase` full HTTP request/response cycle against a real kernel | Revert guard block + controller catch + docs; adds one new failure code, no success-path contract change |

If **feature-branch-chain** is chosen: PR 1 base = tracker/feature branch, PR 2 base = PR 1 branch, PR 3 base = PR 2 branch.

## Phase 1: Locking Finder (Foundation — Unit 1)

- [x] 1.1 RED: In new `apps/api/tests/Integration/AgentReplyAutoClaimConcurrencyTest.php`, write the lock-blocking proof. **Deviation (documented in test docblock)**: a naive "call the whole use case and assert it eventually throws" test does NOT discriminate old vs. new code — `$ticket->markUpdated()` unconditionally writes the tickets row every successful pass, and MySQL's FK check on the `assignments.ticket_id` insert also implicitly locks the parent row, so the PRE-FIX code already blocks+throws too, just later (verified empirically). Replaced with a discriminating repository-level test (`testFindByIdForUpdateBlocksOnRowLockWhereasPlainFindByIdNever`) that proves the OLD `findById()` never contends for the lock while the NEW `findByIdForUpdate()` does, plus a full-flow safety-net test (`testConcurrentReplyRollsBackCleanlyUnderLockContention`, reframed as a safety net, not a discriminator).
- [x] 1.2 GREEN: Added `TicketRepository::findByIdForUpdate()` using `setLockMode(LockMode::PESSIMISTIC_WRITE)` + `Query::HINT_REFRESH`.
- [x] 1.3 REFACTOR: Method signature/conventions match `AssignTicketUseCase`/`AutoAssignTicketUseCase` repository usage.

## Phase 2: Serialize Ownership Decision in Use Case (Unit 1)

- [x] 2.1 RED/proof: In the same concurrency test file, wrote the last-commit-wins proof — `pcntl_fork` two child processes, each booting a real kernel, each calling `processOutboundMessage()` for a different agent on the same ticket. Asserts exactly one final owner, an unbroken `previous_agent_id` chain, and no `ConcurrencyException`. **`pcntl` extension confirmed UNAVAILABLE in this container** (checked early, task 6.3) — test skips gracefully via `markTestSkipped()`; the lock-blocking proof (Phase 1) carries the concurrency-proof burden alone, exactly as the design anticipated.
- [x] 2.2 GREEN: Replaced `findById()` with `findByIdForUpdate()` at `HandleOutboundMessageUseCase.php` as the transaction's first statement.
- [x] 2.3 RED/GREEN: In new `apps/api/tests/Integration/AgentReplyAutoClaimTest.php`, added first-ever `agentId>0` coverage — claim-unassigned, reassign-different-agent, no-op-current-owner scenarios (spec: Reply-Triggered Ownership Claim). All pass.
- [x] 2.4 VERIFY: Confirmed 2.1 (skipped, documented) and 2.3 (passing) against the 2.2 implementation.

## Phase 3: EventCollector Leak Fix (Unit 2)

- [x] 3.1 RED/proof: In `AgentReplyAutoClaimTest.php`, added the collector-leak proof. **Deviation (documented in test docblock)**: "bump `ticket.version` via a 2nd connection" is no longer reproducible once D1's pessimistic lock is the transaction's first statement — any external writer now BLOCKS instead of racing (proven by the concurrency test), so `OptimisticLockException` can no longer occur mid-flight in normal operation. Instead forced the abort via the NEW `\Throwable` branch using a single fault-injecting test double for `RuleExecutorService` (the last collaborator called, after `recordOwnershipChange`/Message/ProcessedEvent were already flushed within the same open transaction) — every other collaborator (EM, EventCollector, AssignmentService, repositories) stayed real. Asserts full rollback, cleared collector, and that a retry produces exactly one assignment + one `TicketUpdatedEvent` with no duplicate `seq`.
- [x] 3.2 GREEN: Moved `persistEventsToOutbox()` out of the `try` block, after the retry loop (matches `AssignTicketUseCase:118-121`).
- [x] 3.3 GREEN: Added `$this->eventCollector->clear()` to the existing `OptimisticLockException` branch AND a new `\Throwable` branch.
- [x] 3.4 VERIFY: Re-ran the collector-leak test; confirms exactly one event, no duplicate seq.

## Phase 4: Resolved/Closed Ownership Guard (Unit 3)

- [x] 4.1 RED/GREEN: Unit test — guard truth table (status × `senderIsPrivileged` × owner × `agentId<=0`) with ZERO mocks (`TicketOwnershipGuardTest.php`). **Deviation**: extracted the guard decision into a pure `TicketOwnershipGuard` class (strict-tdd Extract-Before-Mock rule) instead of a heavily-mocked test driving the whole transactional use case — same behavior as design.md's inlined contract, fully unit-testable.
- [x] 4.2 RED/GREEN: In `AgentReplyAutoClaimTest.php`, added 3 resolved/closed scenarios — supervisor reply (delivered, no reassignment), owning-agent reply (delivered, no reassignment), non-owning-agent reply (`StateConflictException`, no message/assignment/outbox row — rollback proof), plus 2 triangulation cases (CLOSED status, `agentId<=0` never blocked).
- [x] 4.3 GREEN: Added 5th param `bool $senderIsPrivileged = false` to `processOutboundMessage()`; added resolved/closed branch throwing `StateConflictException` for non-owning, non-privileged, `agentId>0` senders; sets `$skipOwnershipChange`.
- [x] 4.4 REFACTOR: Verified guard precedence — lock → guard → `ConversationWindowPolicy` → conversation → auto-claim (D5).

## Phase 5: Controller Wiring — HTTP 409 (Unit 3)

- [x] 5.1 RED/GREEN: New `apps/api/tests/Integration/ProviderProxyControllerAutoClaimTest.php` (WebTestCase) — non-owning `ROLE_AGENT` reply to resolved ticket gets 409 `{"type":"TICKET_NOT_OWNED",...}`. (Note: spec.md's original draft said 403/`TICKET_CLOSED_NOT_OWNER`; design.md D4 explicitly supersedes this with 409/`TICKET_NOT_OWNED` via `StateConflictException`, matching the orchestrator's explicit instruction — implemented per design, not spec's earlier draft.)
- [x] 5.2 GREEN: `ProviderProxyController::send()` — computes `$senderIsPrivileged = $this->isGranted('ROLE_SUPERVISOR')`, passes to use case; catches `StateConflictException` → 409 JSON response.
- [x] 5.3 VERIFY: Confirmed existing `agentId: 0` call sites are unaffected (default param preserves behavior) — full `ProviderProxyController`/`HandleOutboundMessageUseCase` regression suite passes (see Phase 6.2).

## Phase 6: Documentation & Full Regression (Unit 3)

- [x] 6.1 Updated `docs/realtime/events.md` — documented both `AUTO_ASSIGN` sources, reply-triggered `REASSIGN`, and the resolved/closed guard's 409 behavior.
- [x] 6.2 Ran full suite (`docker compose exec php php -d memory_limit=1G bin/phpunit`); live baseline confirmed 59/593 failing+erroring (18 errors + 41 failures) both BEFORE and AFTER this change — did not increase.
- [x] 6.3 Confirmed `pcntl` availability early (before touching any file): **NOT available** in this container (`php -m | grep pcntl` → empty). Documented per design: the lock-blocking proof (Phase 1) alone carries the concurrency-proof burden; the fork test (Phase 2.1) skips gracefully via `markTestSkipped()` without failing the suite.
