# Verification Report: agent-reply-auto-claim

**Change**: Agent Reply Auto-Claim — Harden Existing Behavior
**Mode**: Full artifacts (proposal + specs + design + tasks + apply-progress), hybrid persistence
**Verified**: 2026-09-11
**Verdict**: PASS WITH WARNINGS

## 1. Task Completeness

21/21 tasks in `tasks.md` marked `[x]`, independently cross-checked against actual working-tree code (not trusted from narrative alone). All deviations noted in tasks.md were verified as real and reasonable (see §7).

## 2. Build / Test Evidence (independently executed, not trusted from apply-progress)

| Command | Result |
|---|---|
| `docker exec inbiox_php php bin/phpunit --filter AgentReplyAutoClaim` | 12 tests, 47 assertions, 1 skipped (pcntl), exit 0 |
| `docker exec inbiox_php php bin/phpunit --filter "TicketOwnershipGuard\|ProviderProxyControllerAutoClaim"` | 9 tests, 17 assertions, exit 0 |
| `docker exec inbiox_php php -d memory_limit=1G bin/phpunit` (full suite) | **614 tests, 3546 assertions, 18 errors, 41 failures (=59 failing/erroring), 2 skipped** — exit non-zero due to pre-existing failures only |
| `docker exec inbiox_php php -m \| grep -i pcntl` | empty, exit 1 — pcntl confirmed unavailable in this container |

New test total: 12 + 9 = 21, matching the 21 claimed new tests. All 21 pass or skip-gracefully (1 skip = the pcntl fork test).

Full-suite figure **614/59/2-skipped exactly matches** the apply-progress "after" claim. Baseline-drift note: an earlier change this session (`reports-csv-export`, archived) measured 593/58 before this change's own work began; this change's own pre-change measurement was 593/59 (not re-verified here since re-running against a stashed tree risked the working copy — accepted apply's own two same-session measurements: 593/59 before, 614/59 after, unchanged). The 58→59 drift between sessions is pre-existing flakiness already documented in the `reports-csv-export` verify-report (`AuthorizationTest::testUnauthenticatedRequestToReportsReturns401`), not something introduced by this change. This change's own delta (59→59, unchanged) is the load-bearing claim and is independently confirmed.

## 3. Locking Finder — Genuinely First-Statement, Not TOCTOU Re-Introduction

Confirmed by direct source read:
- `TicketRepository::findByIdForUpdate()` (`apps/api/src/Repository/TicketRepository.php:82-91`) builds a DQL query with `->setLockMode(LockMode::PESSIMISTIC_WRITE)` and `->setHint(Query::HINT_REFRESH, true)` — a genuine locking read, not `findById()` + separate `$em->lock()`.
- `HandleOutboundMessageUseCase::executeTransactionWithRetry()` (line 182) calls `$this->ticketRepo->findByIdForUpdate($ticketId)` as the literal first statement inside the `transactional()` closure — before the guard check, before the window-policy check, before any other DB access.
- The old `findById()` still exists on the repository (used elsewhere, e.g. controllers) but is no longer called from this use case's transactional path.

## 4. Lock-Blocking Proof — Genuinely Discriminating (independently re-run)

Read and ran `AgentReplyAutoClaimConcurrencyTest::testFindByIdForUpdateBlocksOnRowLockWhereasPlainFindByIdNever`. Confirmed it is a genuine comparative proof, not a one-sided assertion:
1. A second real DBAL connection (`DriverManager::getConnection(...)`) holds `SELECT ... FOR UPDATE` on the ticket row inside an open transaction.
2. The test first asserts the OLD `findById()` succeeds immediately under this exact contention (`assertNotNull`) — proving the old path never contends for the lock.
3. It then asserts the NEW `findByIdForUpdate()` blocks and fails specifically with a lock-wait-timeout message under the identical contention.

This is the genuinely discriminating structure apply's own notes describe (their first attempt's "call the whole use case" version was a false-positive RED because `markUpdated()` + the FK check on the `assignments` insert also implicitly locks the parent row, masking the absence of an explicit lock). Independently re-ran: passes.

## 5. EventCollector Leak Fix

Confirmed by reading the actual control flow in `HandleOutboundMessageUseCase.php`:
- `$this->eventCollector->clear()` is called in the `catch (OptimisticLockException $e)` branch (line 113) AND in a new `catch (\Throwable $e)` branch (line 141) that rethrows after clearing.
- `$this->persistEventsToOutbox()` (line 157) is called AFTER the `while` retry loop closes, fully outside the `try`/`catch` structure — not merely moved to the end of a try block.
- Test `testEventCollectorDoesNotLeakEventsFromARolledBackAttempt` fault-injects `RuleExecutorService` (the last collaborator invoked, after ownership/message/processed-event are already flushed within the same open transaction) to force the `\Throwable` branch, and asserts zero leaked rows, a cleared collector, and exactly one non-duplicate `TicketUpdatedEvent`/`seq` on retry. Independently re-run: passes.

## 6. Resolved/Closed Guard

`TicketOwnershipGuard::blocksReassignment()` (`apps/api/src/Domain/Ticket/TicketOwnershipGuard.php:35-46`):
```php
return !$senderIsPrivileged && $agentId > 0 && $assignedAgentId !== $agentId;
```
guarded by `isResolvedOrClosed($status)` first. Confirmed three-way logic:
- `$senderIsPrivileged` (ROLE_SUPERVISOR/ROLE_ADMIN, via `role_hierarchy` inheritance) → never blocks.
- Owning `ROLE_AGENT` (`$assignedAgentId === $agentId`) → never blocks.
- Non-owning `ROLE_AGENT` → blocks (throws `StateConflictException`).
- `$agentId <= 0` → never blocks (system/legacy callers).

Guard precedence confirmed by statement order in `executeTransactionWithRetry()`: lock (line 182) → guard (lines 194-201) → `ConversationWindowPolicy::isOpenForSending()` (line 207) → conversation lookup → auto-claim block (line 220). Matches design.md D5 exactly.

`ProviderProxyController::send()`:
- `$senderIsPrivileged = $this->isGranted('ROLE_SUPERVISOR')` (line 87).
- `catch (StateConflictException $e)` → `new JsonResponse(['type' => 'TICKET_NOT_OWNED', 'message' => $e->getMessage()], Response::HTTP_CONFLICT)` (lines 123-131) — HTTP 409, matching design.md D4's superseding decision over spec.md's original 403/TICKET_CLOSED_NOT_OWNER draft. This supersession is explicit, orchestrator-approved, and intentional — not flagged as a spec/code discrepancy per the launch instructions. Confirmed the *exact implemented* status/body (409, `{"type":"TICKET_NOT_OWNED","message":...}`) matches design.md D4 byte-for-byte in shape.
- HTTP-level test `ProviderProxyControllerAutoClaimTest::testSendReturns409WithTicketNotOwnedTypeWhenNonOwningAgentRepliesToAResolvedTicket` independently re-run: passes, asserts 409 + `type === 'TICKET_NOT_OWNED'` + zero persisted message rows.

## 7. TicketOwnershipGuard Extraction — Assessed as Reasonable, Not a Deviation That Matters

Design.md's key code contract inlines the guard logic directly in the use case. Apply extracted it into `App\Domain\Ticket\TicketOwnershipGuard` (a `final` class with two static, side-effect-free methods). Assessment:
- Behavior is byte-for-byte identical to design's inlined contract (verified by direct comparison of the extracted predicate against design.md's `if` block).
- Enables the zero-mock unit test suite (`TicketOwnershipGuardTest`, 8 tests covering the full status × privileged × owner × agentId truth table) that would otherwise require a heavily-mocked use-case test to reach one `if` branch — a legitimate, narrowly-scoped SRP extraction consistent with the project's Extract-Before-Mock convention.
- No behavior, ordering, or API-contract change results from the extraction; the use case still calls both static methods in the same two places design specifies.

Verdict: reasonable implementation detail, not a design deviation. No WARNING needed.

## 8. Documentation Correction

`docs/realtime/events.md` diff confirmed accurate against the implementation:
- `AUTO_ASSIGN` row now correctly attributes two sources (`AutoAssignTicketUseCase` + `HandleOutboundMessageUseCase`), matching the code's `AssignmentType::AUTO_ASSIGN` branch when `$currentAssigneeId === null`.
- `REASSIGN` row now correctly attributes two sources, and explicitly notes "any role" — matching the eligibility check's absence of any role condition.
- New paragraph documents the 409 `TICKET_NOT_OWNED` guard as first-class, intentional behavior — matches §6 findings exactly (not merely "docs were touched").

## 9. Scope Discipline — `AssignTicketUseCase` Confirmed Untouched

`git diff --stat -- apps/api/src/Application/UseCase/AssignTicketUseCase.php` returns empty — zero changes. The known-identical `findById()`-before-`lock()` staleness bug in `AssignTicketUseCase` (flagged as an explicit out-of-scope follow-up in design.md's Open Questions) was correctly left untouched, as scoped.

## 10. Regression Safety

`processOutboundMessage()`'s 5th parameter `bool $senderIsPrivileged = false` has a safe default; all pre-existing `agentId: 0` call sites are unaffected because `TicketOwnershipGuard::blocksReassignment()` short-circuits to `false` whenever `$agentId <= 0`, and the auto-claim block itself is gated on `$agentId > 0`. Confirmed via full-suite re-run (§2): no increase in the pre-existing 59 failing/erroring baseline.

## 11. pcntl Skip

Confirmed `testConcurrentRepliesFromDifferentAgentsProduceDeterministicLastCommitWinsOwnership` calls `$this->markTestSkipped(...)` with an explicit, descriptive message when `!\extension_loaded('pcntl')` (line 189-195) — a genuine skip, not a silent pass or a swallowed error. Independently confirmed pcntl absence via `php -m | grep -i pcntl` (empty, exit 1) and via the full-suite run reporting exactly 2 skipped (1 expected pre-existing + 1 new pcntl skip).

## 12. Spec Compliance Matrix

### Domain: reply-auto-assignment (4 requirements, 10 scenarios)

| Requirement / Scenario | Status | Evidence |
|---|---|---|
| Reply claims an unassigned ticket | PASS | `testReplyClaimsAnUnassignedTicket` |
| Reply reassigns ticket owned by different agent | PASS | `testReplyReassignsATicketOwnedByADifferentAgent` |
| Reply by current owner causes no reassignment | PASS | `testReplyByCurrentOwnerCausesNoReassignment` |
| Any eligible role triggers reassignment (no role exclusion) | **WARNING — inferred, not directly role-differentiated at runtime** | No integration/HTTP test drives the auto-claim (non-resolved) path through the controller with a ROLE_SUPERVISOR/ROLE_ADMIN session to prove reassignment occurs identically to ROLE_AGENT. Source inspection confirms the auto-claim block (`HandleOutboundMessageUseCase.php:220`) contains no role condition at all — only `$agentId > 0` and ownership comparison — so this is architecturally sound, but not runtime-proven per-role. Low risk (same code path already covered for ROLE_AGENT-equivalent inputs). |
| Resolved/closed: supervisor delivered, no reassignment | PASS | `testSupervisorReplyToResolvedTicketIsDeliveredWithoutReassignment` |
| Resolved/closed: owning agent delivered, no reassignment | PASS | `testOwningAgentReplyToOwnResolvedTicketIsDeliveredWithoutReassignment` |
| Resolved/closed: non-owning agent blocked | PASS | `testNonOwningAgentReplyToResolvedTicketIsBlocked` + `testNonOwningAgentReplyToClosedTicketIsBlocked` (unit-triangulation) + HTTP `testSendReturns409...` |
| Concurrent replies serialized, deterministic last-commit-wins owner, accurate previousAgent chain, no ConcurrencyException ≤3 replies | **WARNING — end-to-end scenario test SKIPPED (pcntl unavailable)** | `testConcurrentRepliesFromDifferentAgentsProduceDeterministicLastCommitWinsOwnership` is written per design intent but skips in this environment. Substitute runtime evidence: `testFindByIdForUpdateBlocksOnRowLockWhereasPlainFindByIdNever` (genuine lock-acquisition proof) + `testConcurrentReplyRollsBackCleanlyUnderLockContention` (clean-rollback safety net) both PASS and jointly demonstrate the serialization *mechanism*, but neither directly exercises two concurrent real committing transactions producing a last-commit-wins owner with a chained `previousAgent` audit trail — that specific scenario remains environment-blocked, not code-defective. |
| Failed attempt does not leak events into successful retry | PASS | `testEventCollectorDoesNotLeakEventsFromARolledBackAttempt` |

### Domain: authorization (delta, 13 scenarios: 12 existing unchanged + 1 new)

| Scenario | Status | Evidence |
|---|---|---|
| 12 pre-existing RBAC scenarios | Unchanged, out of this change's diff | No code touched `TicketVoter`/RBAC matrix; confirmed via proposal/design scope and zero diff on authorization-related source files. |
| New: reply auto-assignment not blocked by "Reassign tickets: ROLE_AGENT ❌" | PASS (indirect) | `testReplyReassignsATicketOwnedByADifferentAgent` proves a non-owning agent's reply reassigns ownership without any authorization denial at the use-case level. This spec change is a clarifying documentation delta (no `TicketVoter` code change), consistent with the proposal's explicit scope. |

## 13. Design Coherence

All six architecture decisions (D1-D6) independently verified against actual code, not narrative:
- D1 (locking DQL finder over `$em->lock()`) — confirmed, §3.
- D2 (single-resource lock, no deadlock risk) — confirmed: exactly one row lock (tickets), acquired first, no second lock acquired anywhere in this transaction.
- D3 (role transport via defaulted boolean param) — confirmed, §6.
- D4 (409 `TICKET_NOT_OWNED` via `StateConflictException`, reusing the existing exception type) — confirmed, §6; `StateConflictException` confirmed pre-existing (`apps/api/src/Exception/StateConflictException.php`), reused not newly introduced.
- D5 (guard precedence before window policy) — confirmed, §6.
- D6 (collector clear-on-abort + outbox flush moved outside retry) — confirmed, §5.

## 14. Issues

### CRITICAL
None.

### WARNING
1. **Concurrency end-to-end scenario (last-commit-wins determinism) has no passing runtime proof in this environment** — the dedicated fork-based test is environment-skipped (pcntl unavailable), and the two passing substitute tests prove the locking mechanism and rollback safety but not the full "two concurrent committing agents → deterministic chained ownership" outcome end-to-end. This is a genuine environment gap, transparently disclosed by apply, not a code defect — but it means one full spec scenario remains formally UNTESTED at runtime pending a pcntl-enabled environment (e.g. CI) picking it up.
2. **"Any eligible role triggers reassignment" scenario lacks a role-differentiated runtime test** — architecturally sound by inspection (no role condition exists in the auto-claim code path) but not directly proven via an HTTP/integration test authenticated as ROLE_SUPERVISOR/ROLE_ADMIN performing a non-resolved-ticket reassignment.

### SUGGESTION
1. Consider adding the pcntl-based concurrency test to a CI environment where the extension is available, so the last-commit-wins scenario gets genuine runtime proof at least in one pipeline stage.
2. Consider one small integration test authenticating as ROLE_SUPERVISOR (not just via `senderIsPrivileged: true` at the use-case call level) reassigning a non-resolved ticket, to close the role-differentiation gap in WARNING #2 with real HTTP-level evidence.

## 15. Final Verdict

**PASS WITH WARNINGS** — 0 CRITICAL, 2 WARNING, 2 SUGGESTION. All 21 tasks complete and independently verified against actual code and passing tests, not narrative. The TOCTOU fix, event-leak fix, and resolved/closed guard are all genuinely implemented and correctly ordered. Both WARNINGs are environment/coverage gaps, not defects in delivered behavior, and do not block delivery, but should be tracked as follow-ups (ideally in CI with pcntl available).
