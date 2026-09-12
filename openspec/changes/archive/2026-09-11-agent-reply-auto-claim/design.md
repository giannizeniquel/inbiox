# Design: Agent Reply Auto-Claim — Harden Existing Behavior

## Technical Approach

Serialize the ownership decision in `HandleOutboundMessageUseCase::executeTransactionWithRetry()` by making the ticket read a **pessimistic locking read** (the first statement in the transaction), then gate the auto-claim block on a new resolved/closed owner rule, then stop the `EventCollector` leaking a rolled-back attempt's events into a later commit. WHO/WHEN eligibility is unchanged: any reply from any role still claims/reassigns an open ticket.

**Scope correction vs. proposal**: proposal "Out of Scope — blocking the send itself on resolved/closed tickets" is superseded by resolved product decision 3. A non-owning `ROLE_AGENT` replying to a `RESOLVED`/`CLOSED` ticket is now **blocked with 409**.

## Architecture Decisions

### D1 — Lock via a locking DQL finder, not `$em->lock()`

**Choice**: new `TicketRepository::findByIdForUpdate()` using `setLockMode(PESSIMISTIC_WRITE)` + `HINT_REFRESH`, replacing `findById()` at line 144 as the transaction's first statement.

```php
public function findByIdForUpdate(int $id): ?Ticket
{
    return $this->createQueryBuilder('t')
        ->where('t.id = :id')->setParameter('id', $id)
        ->getQuery()
        ->setLockMode(LockMode::PESSIMISTIC_WRITE)
        ->setHint(Query::HINT_REFRESH, true)
        ->getOneOrNullResult();
}
```

**Alternatives**: (a) `$em->lock($ticket, PESSIMISTIC_WRITE)` after `findById()`, as `AssignTicketUseCase:75-81`; (b) raw `SELECT * FROM tickets ... FOR UPDATE` + separate entity load, as `AutoAssignTicketUseCase:85`.

**Rationale**: (a) is the codebase convention but is **unsafe under MySQL REPEATABLE READ**: `findById()` opens the transaction's consistent-read snapshot *before* the lock, `lock()` only issues the lock SQL without rehydrating, and a follow-up `refresh()` reads the stale snapshot — the TOCTOU window survives. A locking read as the *first* ticket access reads the latest committed row and hydrates the entity from it. `HINT_REFRESH` overwrites any identity-map copy. (b) needs a second load whose plain SELECT hits the same stale-snapshot problem. Note as follow-up (not this change): `AssignTicketUseCase` carries the same latent staleness.

### D2 — Lock ordering / deadlock safety

**Choice**: exactly one row lock per transaction (`tickets` row), acquired first, never released early.

**Rationale**: a single-resource lock acquired as statement #1 cannot form a wait cycle with another `HandleOutboundMessage` transaction — the loser simply waits. Cross-use-case order stays consistent (`AutoAssignTicketUseCase` locks `tickets` then `agents`; this path locks `tickets` only, never `agents`). Waiting exhaustion raises a DBAL lock-wait-timeout, **not** `OptimisticLockException`, so it never consumes a retry and never silently re-runs the claim. `#[ORM\Version]` + the 3-attempt retry loop stay as defense-in-depth for writers outside this lock.

### D3 — Role transport: explicit boolean parameter

**Choice**: role does **not** reach the use case today (`processOutboundMessage(ticketId, content, agentId, mediaMetadata)` only). Add a 5th, defaulted parameter `bool $senderIsPrivileged = false`. `ProviderProxyController::send()` computes `$this->isGranted('ROLE_SUPERVISOR')` — `role_hierarchy` (`ROLE_ADMIN: [ROLE_SUPERVISOR, ...]`) makes that one check cover admin too.

**Alternatives**: inject `Security` into the use case (couples Application to the HTTP security context, breaks CLI/worker callers, forces tokens into unit tests); pass `array $roles` (duplicates `security.yaml` hierarchy resolution inside the use case).

**Rationale**: defaulted 5th parameter keeps every existing call site and `agentId: 0` test valid; the controller stays the only place role semantics are resolved.

### D4 — Resolved/closed guard: reuse `StateConflictException` → 409

**Choice**: throw the existing `App\Exception\StateConflictException`, caught in `ProviderProxyController::send()` → `409` with body `{"type":"TICKET_NOT_OWNED","message":...}`.

**Alternatives**: a new dedicated exception class; `403 Forbidden`.

**Rationale**: `StateConflictException` already *is* this codebase's "resolved/closed ticket conflict" type (`TicketVoter::ASSIGN:91` → `TicketController::assign:53` → 409). A new class fragments the 409 mapping. 403 is wrong: the role is permitted to send, the *ticket state* conflicts. `{type, message}` matches the controller's `CONVERSATION_WINDOW_EXPIRED` / `PHONE_NOT_FOUND` shape. Thrown inside `$conn->transactional()` → full rollback, no phantom message row.

### D5 — Guard precedence: status/ownership before the window policy

**Choice**: order inside the transaction is `lock → resolved/closed guard → ConversationWindowPolicy → conversation → auto-claim`.

**Rationale**: after the lock so the status is read from the locked row (a concurrent resolve cannot interleave). Before the window check because ownership on a closed ticket is stable and authoritative, while the window is time-dependent — a 422 `CONVERSATION_WINDOW_EXPIRED` would wrongly suggest a template send would succeed, when the agent may not touch the ticket at all. 409 therefore wins over 422 for a non-owning agent.

### D6 — EventCollector: clear on abort, flush outbox post-commit

**Choice**: two edits in `processOutboundMessage`. (1) Move `persistEventsToOutbox()` **out of** the `try`, after the transactional call returns. (2) Clear the collector on **every** abort path.

**Rationale**: today `clear()` runs only at `persistEventsToOutbox():331`, so a rolled-back attempt's `TicketUpdatedEvent` + `InboxUpdatedEvent`s survive into the next attempt's flush — a phantom `TicketAssigned` plus **duplicate `seq`** (the rollback reverts `ticket.last_event_seq`, so the retry re-issues the same numbers). Two clear points are needed: the `OptimisticLockException` branch, and a new `\Throwable` branch so non-retryable aborts (window expired, state conflict) cannot leak events into a later use case in the same request. Keeping the outbox flush inside the `try` also lets an outbox-flush `OptimisticLockException` re-execute the entire transaction — moving it out matches `AssignTicketUseCase:118-121` and `AutoAssignTicketUseCase:55-62`.

## Data Flow

```
POST /api/provider/send
  ProviderProxyController::send()
      resolves $agentId (auto-vivifies Agent), $senderIsPrivileged = isGranted(ROLE_SUPERVISOR)
          │
          ▼
  processOutboundMessage(..., senderIsPrivileged)
      idempotency check (ProcessedEvent) ─── duplicate_skipped ──▶ return
          │
          ├─ attempt loop (≤3) ───────────────────────────────────┐
          │    transactional {                                    │
          │      ① findByIdForUpdate  → SELECT … FOR UPDATE ◀ serialization point
          │      ② resolved/closed guard → StateConflict ▶ 409     │
          │      ③ ConversationWindowPolicy → 422                  │
          │      ④ auto-claim (skipped when ②'s allow-no-reassign) │
          │      ⑤ message + FSM + ProcessedEvent → COMMIT         │
          │    }                                                  │
          │  catch OptimisticLock → collector.clear(); backoff ────┘
          │  catch Throwable      → collector.clear(); rethrow
          ▼
      persistEventsToOutbox()   (post-commit, outside try)
          ▼
      outbox_events → Mercure → TicketStore
```

## Interfaces / Contracts

```php
// HandleOutboundMessageUseCase — new signature
public function processOutboundMessage(
    int $ticketId, string $content, int $agentId,
    ?array $mediaMetadata = null, bool $senderIsPrivileged = false,
): array;

// Inside the transaction, immediately after the locking read (replaces lines 144-147):
$ticket = $this->ticketRepo->findByIdForUpdate($ticketId);
if (!$ticket) { throw new \InvalidArgumentException('Ticket not found'); }

$status = $ticket->getStatus();
$skipOwnershipChange = false;
if ($status === TicketStatus::RESOLVED->value || $status === TicketStatus::CLOSED->value) {
    if (!$senderIsPrivileged && $agentId > 0 && $ticket->getAssignedAgentId() !== $agentId) {
        throw new StateConflictException(
            sprintf('Cannot send to ticket in "%s" state: sender is not the assigned owner', $status)
        );
    }
    $skipOwnershipChange = true;   // supervisor/admin, owner agent, or agentId 0 → send, no reassign
}
// …window policy…conversation…
if (!$skipOwnershipChange && $agentId > 0 && $currentAssigneeId !== $agentId) { /* existing block */ }
```

`$agentId <= 0` never blocks and never reassigns — preserves existing `agentId: 0` callers/tests.

```php
// ProviderProxyController::send() — new catch beside the window catch (line 109)
} catch (StateConflictException $e) {
    return new JsonResponse(
        ['type' => 'TICKET_NOT_OWNED', 'message' => $e->getMessage()],
        Response::HTTP_CONFLICT
    );
}
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` | Modify | Locking read, resolved/closed guard, `$senderIsPrivileged` param, collector clear on abort, outbox flush post-commit |
| `apps/api/src/Repository/TicketRepository.php` | Modify | Add `findByIdForUpdate()` |
| `apps/api/src/Controller/ProviderProxyController.php` | Modify | Pass `senderIsPrivileged`, catch `StateConflictException` → 409 |
| `apps/api/tests/Integration/AgentReplyAutoClaimTest.php` | Create | Claim / reassign / three resolved-closed scenarios / collector-leak proof |
| `apps/api/tests/Integration/AgentReplyAutoClaimConcurrencyTest.php` | Create | Lock-blocking proof + fork-based last-commit-wins proof |
| `apps/api/tests/Integration/ProviderProxyControllerAutoClaimTest.php` | Create | HTTP 409 body/status for the blocked send |
| `docs/realtime/events.md` | Modify | Lines 122/124: name both `AUTO_ASSIGN` sources and the reply-triggered `REASSIGN` source |

## Testing Strategy

Baseline is zero coverage: every existing test passes `agentId: 0` and short-circuits this path.

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Integration | Claim: unassigned + `agentId>0` reply | Assert `ticket.assigned_agent_id`, one `AUTO_ASSIGN` assignment row, `previous_agent_id` null, `last_read_at` set |
| Integration | Reassign: A owns, B replies | Assert owner = B, `REASSIGN` row with `previous_agent_id` = A |
| Integration | Resolved/closed — supervisor | `senderIsPrivileged: true` → message persisted, owner unchanged, **zero** new assignment rows |
| Integration | Resolved/closed — owning agent | owner replies → message persisted, owner unchanged, zero assignment rows |
| Integration | Resolved/closed — non-owning agent | Expect `StateConflictException`; assert **no** message row, no assignment row, no outbox row (rollback proof) |
| HTTP | Blocked send surface | `WebTestCase` (pattern of `ProviderProxyControllerConversationWindowTest`): assert `409` + `{"type":"TICKET_NOT_OWNED"}` |
| Integration | **Lock proof** (deterministic) | Second DBAL connection holds `SELECT … FOR UPDATE` on the ticket in an open transaction; main connection sets `innodb_lock_wait_timeout = 1` and calls `processOutboundMessage` → must block then fail on lock wait. Asserts the lock is real *and* gates the decision (no message/assignment row written). Non-flaky, single process. |
| Integration | **Last-commit-wins proof** | `pcntl_fork` two children, each booting its own kernel and calling the **real** use case for a different agent on the same ticket (pattern of `Sprint10ConcurrencyTest`, but driving the use case, not raw SQL). Invariants: exactly one final owner; assignment rows form an unbroken chain (`previous_agent_id` of row *n* = `agent_id` of row *n-1*); `ticket.assigned_agent_id` equals the newest row's `agent_id`; no `ConcurrencyException`. |
| Integration | **Collector-leak proof** | Force attempt #1 to fail after `recordOwnershipChange` (bump `ticket.version` from a second connection between the claim and the final flush) → attempt #2 commits. Assert `outbox_events` holds exactly one `TicketUpdatedEvent` for the ticket and no duplicate `seq`. Before the fix this yields two events with colliding `seq`. |
| Unit | Guard truth table | Pure decision test over (status × privileged × owner × `agentId<=0`) with mocked repos, no DB |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migration required. No schema change, no API contract change for the success path (the 409 is a new failure code on an existing endpoint), no frontend change — the Mercure pipeline already propagates ownership. Single-commit revert.

## Open Questions

- [ ] None blocking. Follow-up (out of scope): `AssignTicketUseCase` has the same `findById`-before-`lock()` stale-snapshot pattern described in D1.
