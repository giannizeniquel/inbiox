# Tasks: WhatsApp 24h Conversation-Window Compliance

> **Scope**: 45 tasks across 7 phases (Phase 0–6). Strict TDD baselined against ~58 pre-existing PHPUnit failures (not zero) — every task touching production logic is preceded by its own failing-test task. Frontend Karma is unrunnable here; `ng build --configuration production` is the enforceable frontend gate.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | **~1,050–1,100** (backend production ~548, backend tests ~430, frontend ~90) |
| 400-line budget risk | High |
| 800-line budget risk | **High** — meaningfully over 800 |
| Chained PRs recommended | **Yes** |
| Suggested split | 5 work units (see below), mappable to stacked or feature-branch-chain PRs |
| Delivery strategy (preflight) | single-pr |
| Chain strategy | pending |

Decision needed before apply: **Resolved — 2026-08-28**
Chained PRs recommended: Yes (declined by user)
Chain strategy: **`size:exception` approved — single PR**
800-line budget risk: **High**

**Decision**: the estimate (~1,050–1,100 changed lines: ~19 backend files, ~10 new/modified test files, 5 frontend files, 1 migration, 1 compose-service edit) is meaningfully over the 800-line budget, not a rounding margin. Brought back to the user per this project's guard rule before `sdd-apply` started. **User approved `size:exception` — one single PR**, the same call made for `file-attachments` (~900-1100 lines, also approved as a single-PR exception). The 5 work units below remain useful as internal commit boundaries and rollback checkpoints, not as separate PRs.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Phase 0 — migration + domain primitives (`ConversationWindowPolicy`, `TicketEventType`, `ConversationWindowExpiredException`, FSM widening) | PR 1 | `bin/phpunit --filter ConversationWindowPolicyTest\|TicketStateMachineTest` | `docker compose exec php bin/phpunit` (requires `make up`; Docker currently down in this environment per `openspec/config.yaml`) | `down()` the migration + revert the 5 new/modified domain files; nothing else depends on this yet |
| 2 | Phase 1 — write-path guards (`HandleOutboundMessageUseCase`, `ProviderProxyController` catch, `AutoReplyService`) | PR 2 | `bin/phpunit --filter HandleOutboundMessageUseCaseTest\|AutoReplyServiceTest\|ProviderProxyControllerTest` | same as above | Revert the 3 guard insertions; depends on Unit 1's policy/exception existing |
| 3 | Phase 2+3 — close path + reopen path (`closeDueToWindowExpiry()`, `reopenDueToInboundMessage()`, `findWindowExpiredClosedByContact()`, `HandleInboundMessageUseCase` wiring) | PR 3 | `bin/phpunit --filter TransitionTicketStatusUseCaseTest\|TicketRepositoryTest\|HandleInboundMessageUseCaseTest` | same as above | Revert `TicketStateMachine` widening, the two new use-case methods, the repository method, and the `HandleInboundMessageUseCase` reopen block; must ship/revert as one unit — close and reopen are tightly coupled by design |
| 4 | Phase 4+5 — closure audit timeline branch + REST baseline + composer UI | PR 4 | `bin/phpunit --filter GetContactTimelineQueryTest` + `cd apps/web && npx ng build --configuration production` | `ng build` confirmed exit 0 in this environment; PHPUnit as above | Independently revertible per design.md's rollback plan — frontend falls back to always-enabled; drop the `UNION ALL` branch and the `timestamps` field |
| 5 | Phase 6 — scheduled closure job (Command + Service + `window-closer` compose service) | PR 5, **must merge last** | `bin/phpunit --filter ConversationWindowExpiryServiceTest\|CloseExpiredConversationWindowsCommandTest` | manual `--dry-run` against production-like data (design.md's explicit gate) | Stop/remove the `window-closer` compose service first, then remove the Command/Service files — this ordering is load-bearing (design.md: stop the loop before any other rollback step) |

---

## Phase 0 — Foundation: Migration + Domain Primitives

*Must land before anything else — every later phase depends on these types.*

- [x] 0.1 RED: `ConversationWindowPolicyTest` — boundary table (exactly-24h, 24h±margin), `isOpenForSending(null,...)` fails closed, `isExpiredForClosing(null,...)` fails open (D8). New file `apps/api/tests/Unit/Domain/Ticket/ConversationWindowPolicyTest.php`.
- [x] 0.2 GREEN: create `App\Domain\Ticket\ConversationWindowPolicy` (`WINDOW_HOURS=24`, `$safetyMarginMinutes` ctor, `isOpenForSending()`, `isExpiredForClosing()`, `expiresAt()`) per design.md's interface. New file `apps/api/src/Domain/Ticket/ConversationWindowPolicy.php`.
- [x] 0.3 Bind the safety-margin default: add `CONVERSATION_WINDOW_SAFETY_MARGIN_MINUTES=5` to `apps/api/.env` (spec.md already chose 5 minutes — this is plugging in the value, not a new decision) and bind `$safetyMarginMinutes: '%env(int:CONVERSATION_WINDOW_SAFETY_MARGIN_MINUTES)%'` in `apps/api/config/services.yaml`.
- [x] 0.4 Create `App\Domain\Event\TicketEventType` with `WINDOW_EXPIRED_CLOSED` / `WINDOW_REOPENED` constants. New file `apps/api/src/Domain/Event/TicketEventType.php`. Existing raw-string types (`auto_closed`, `tag_added`, ...) stay untouched.
- [x] 0.5 Create `App\Exception\ConversationWindowExpiredException` carrying `ticketId` + `lastInboundMessageAt`. New file `apps/api/src/Exception/ConversationWindowExpiredException.php`.
- [x] 0.6 Migration `apps/api/migrations/Version20260828000000.php`: add `tickets.last_inbound_message_at DATETIME NULL`; index `idx_tickets_status_last_inbound (status, last_inbound_message_at)`; index `idx_ticket_events_ticket_created (ticket_id, created_at, id)`. **Gotcha**: `ticket_events` currently has no index but its PK (verified in `Version20260505022731`) — both the reopen subquery (0.6/3.2) and the timeline branch (4.2) filter on `ticket_id`, so this index is load-bearing, not optional. `down()` drops all three.
- [x] 0.7 Add `lastInboundMessageAt` column + getter/setter to `Ticket`, mirroring `lastReadAt` (L64-65). File `apps/api/src/Domain/Ticket/Ticket.php`.
- [x] 0.8 RED: extend `TicketStateMachineTest` — assert `new|active|waiting → closed` now valid, every `closed → *` still invalid, `isTerminal('closed')` still `true`.
- [x] 0.9 GREEN: widen `TicketStateMachine::TRANSITIONS` — add `CLOSED => true` under `NEW`, `ACTIVE`, `WAITING` (Option A, never routed through `resolved`); update the class docblock. File `apps/api/src/Domain/Ticket/TicketStateMachine.php`.

## Phase 1 — Write-Path Guards (Free-Text Blocking Before Persist)

*Depends on Phase 0 (`ConversationWindowPolicy`, `ConversationWindowExpiredException`).*

- [x] 1.1 RED: integration test — `HandleOutboundMessageUseCase` on an expired-window ticket asserts zero new `messages` rows AND unchanged ticket status/FSM state (guard-before-persist, per spec's "no phantom message" requirement).
- [x] 1.2 GREEN: insert the window guard inside `HandleOutboundMessageUseCase::executeTransactionWithRetry()` immediately after the ticket fetch (~L141), **before** `$this->em->persist($msg)` (L198) and before any FSM transition — throw `ConversationWindowExpiredException` when `isOpenForSending()` is false. File `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php`.
- [x] 1.3 RED: `ProviderProxyController` integration test — sending on an expired-window ticket returns 422 with `{type: 'CONVERSATION_WINDOW_EXPIRED', ...}`, mirroring the existing 422/`MESSAGE_TOO_OLD` shape.
- [x] 1.4 GREEN: add `catch (ConversationWindowExpiredException $e)` around the `processOutboundMessage()` call at L98 in `ProviderProxyController::send()`. **Gotcha (confirmed uncaught today)**: without this catch the exception propagates as a 500. Apply the same catch to the media-send action. File `apps/api/src/Controller/ProviderProxyController.php`.
- [x] 1.5 RED: `AutoReplyService::doMaybeSend()` test — on an expired window, asserts an early return, a `logger->info` call, no `GraphClient::sendMessage()` call, and no `Message` persisted.
- [x] 1.6 GREEN: insert the window guard in `AutoReplyService::doMaybeSend()` before `$this->graphClient->sendMessage()` at L121 — **early `return`**, not a throw (fire-and-forget: "no auto-reply" is not an error). File `apps/api/src/Application/Service/AutoReplyService.php`.

## Phase 2 — Close Path (FSM Widening Already Landed in 0.9)

*Depends on Phase 0 (`TicketEventType`, widened FSM).*

- [x] 2.1 RED: `TransitionTicketStatusUseCaseTest` — `closeDueToWindowExpiry()` transitions a `new`/`active`/`waiting` ticket to `closed` and writes a `TicketEvent(WINDOW_EXPIRED_CLOSED, 'system')` row; rejects an already-`closed` ticket via the FSM's own guard.
- [x] 2.2 GREEN: implement `closeDueToWindowExpiry(int $ticketId): array` — transactional + `SELECT ... FOR UPDATE`; validity delegated to `$this->stateMachine->transition($status, 'closed')`; reuse `recordUnassignIfOwner()`; `UPDATE status='closed', closed_at=NOW(), updated_at=NOW()`; persist `TicketEvent`. File `apps/api/src/Application/UseCase/TransitionTicketStatusUseCase.php`. `close()`/`execute()` stay untouched.
- [x] 2.3 RED (regression guard): test asserting `POST /tickets/{id}/close` still rejects non-`resolved` tickets — "No Regression to Manual Closure" spec requirement.
- [x] 2.4 Run 2.3 — expected to pass immediately since `close()`'s hardcoded `RESOLVED`-only guard was never touched by 2.2. If it fails, that is a real regression to fix before Phase 3.

## Phase 3 — Reopen Path (System-Triggered Reactivation)

*The two additive methods (3.2, 3.4) MUST land before wiring the `HandleInboundMessageUseCase` insertion point (3.7).*

- [x] 3.1 RED: `TicketRepositoryTest` — `findWindowExpiredClosedByContact()` matches only a closed ticket whose **latest** `ticket_events.type = 'window_expired_closed'`; zero events, a different latest type, or a closed-for-another-reason ticket → no match; tie-break `created_at DESC, id DESC`; multiple eligible tickets → most recent by `updated_at`.
- [x] 3.2 GREEN: implement `findWindowExpiredClosedByContact()` using the raw-SQL correlated subquery from design.md (DQL cannot express a correlated `ORDER BY ... LIMIT 1`), rehydrate via `$this->find($id)`. Optional `phoneNumberId` filter (CAP-7 parity). `findNotClosedByContact()` / `findOpenByContact()` are **not modified**. File `apps/api/src/Repository/TicketRepository.php`.
- [x] 3.3 RED: `TransitionTicketStatusUseCaseTest` — `reopenDueToInboundMessage()` reopens only when `status === 'closed'` AND the **latest** `ticket_events.type === WINDOW_EXPIRED_CLOSED` (guard re-checked inside the locked transaction, not trusted from the repository lookup); target status `active`, `closed_at=NULL`; writes `TicketEvent(WINDOW_REOPENED)`; rejects otherwise via `\InvalidArgumentException`; never calls `$this->stateMachine`.
- [x] 3.4 GREEN: implement `reopenDueToInboundMessage(int $ticketId): array` exactly per design.md's contract (guard 1: status; guard 2: latest event type, re-checked under the lock; no shared-FSM-table entry ever added for `closed → *`). File same as 3.2.
- [x] 3.5 RED: isolation integration test — `POST /tickets/{id}/transition` targeting **any** status on a `window_expired_closed` ticket returns 4xx. This is the structural guarantee that `reopenDueToInboundMessage()` has exactly one call site and is never human-reachable.
- [x] 3.6 RED: `HandleInboundMessageUseCaseTest` — positive: inbound message on a `window_expired_closed` ticket reuses the same `ticket_id` and lands `active`. Negative: inbound message on a ticket closed for any other reason (or with zero `ticket_events`) still creates a new ticket, unchanged from today.
- [x] 3.7 GREEN: insert the reopen block in `HandleInboundMessageUseCase::executeInternal()` between the current L262 and L265, exactly per design.md's snippet — if `findNotClosedByContact()` returns null, call `findWindowExpiredClosedByContact()`; if found AND `getConversation()?->getId() === $conversation->getId()`, call `reopenDueToInboundMessage()`, set `$ticket = $reopenable`, `$wasReopened = true`. **Gotcha (confirmed)**: after `$this->em->lock($ticket, PESSIMISTIC_WRITE)`, call `$this->em->refresh($ticket)` when `$wasReopened` — `reopenDueToInboundMessage()` writes via raw SQL, so the managed entity still reports `status='closed'` and step 7's `waiting→active` branch logic would read a stale status without the refresh. File `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php`.
- [x] 3.8 Confirm (no new code expected): step 8 (~L296-301), `$ticket->setLastInboundMessageAt($msg->getCreatedAt())`, still fires for both the reused/reopened ticket and the normal path — this remains the single writer for the field added in Phase 0, unaffected by 3.7.

## Phase 4 — Closure Audit: Timeline Branch

*Depends on Phase 0 (`TicketEventType`) and Phase 2+3 (both event types must exist for the allow-list to be meaningful).*

- [x] 4.1 RED: `GetContactTimelineQueryTest` — `window_expired_closed` and `window_reopened` events appear in a contact's timeline; other `ticket_events` types (`tag_added`, `auto_reopened`, etc.) do NOT appear (D6 allow-list — reading every type would be an out-of-scope behavior change).
- [x] 4.2 GREEN: add a third `UNION ALL` branch to `GetContactTimelineQuery`, column-compatible with the existing two, reading only `te.type IN ('window_expired_closed','window_reopened')`, via a `$ticketEventWhere` fragment following the existing `$outboxWhere`/`$assignmentWhere` convention. No new `buildParams()`/`buildTypes()` params needed (reuses `:contact_id`, `:before_date`, `:phone_number_id`). File `apps/api/src/Application/Query/GetContactTimelineQuery.php`.

## Phase 5 — Read Path: REST Baseline + Composer UI

*5.1/5.2 depend on Phase 0's migration. 5.3–5.7 can proceed in parallel with Phase 2–4 backend work once 0.6/0.7 have landed, since the frontend field is declared optional.*

- [x] 5.1 Add `last_inbound_message_at` to the ticket `timestamps` payload in `GetTicketDetailQuery` (~L69-78) and `ListTicketsQuery` (~L89, L189-192). No DTO change needed — `timestamps` is already a pass-through array. Files `apps/api/src/Application/Query/GetTicketDetailQuery.php`, `ListTicketsQuery.php`.
- [x] 5.2 RED→GREEN: extend the existing ticket-detail/list query tests to assert `timestamps.last_inbound_message_at` is present and correctly sourced.
- [x] 5.3 Add `timestamps.last_inbound_message_at?: string | null` (optional) to the frontend `Ticket` model — keep it optional so the object literals at `ticket.store.ts:144` and `:156` keep compiling. File `apps/web/src/app/core/models/ticket.model.ts`.
- [x] 5.4 Add a `lastInboundMessageAt = computed(...)` signal to `ConversationComponent`: `max(activeTicket()?.timestamps.last_inbound_message_at, latest inbound in conversationStore.messages())` — derived, read-only, writes to no store (D7). File `apps/web/.../containers/conversation/conversation.component.ts`.
- [x] 5.5 Bind `[lastInboundMessageAt]="lastInboundMessageAt()"` on `<app-composer>` in `conversation.component.html` (L72-75).
- [x] 5.6 Add the composer's first-ever `input()`: `lastInboundMessageAt = input<string | null>(null)`; a private `now` signal ticked every 60s and cleaned via `DestroyRef`; `windowExpired = computed(...)` applying the full 24h (accepted UI/backend skew — the backend applies 24h minus the safety margin; the UI is advisory, the backend authoritative); `send()` early-returns when `windowExpired()` is true. File `apps/web/.../components/composer/composer.component.ts`.
- [x] 5.7 Update the composer template: `@if (windowExpired()) { <p class="warning" role="status">…</p> } @else { existing input row }`. File `composer.component.html`.
- [x] 5.8 Verify: `cd apps/web && npx ng build --configuration production` exits 0 (Karma is unrunnable in this environment per `openspec/config.yaml` — this build is the enforceable frontend gate, not a substitute for real component tests).
- [x] 5.9 Document the accepted reopen-visibility limitation as a code comment at the reopen call site in `HandleInboundMessageUseCase` (3.7): a reopened ticket may not appear live in the agent's ticket list because no ticket-level Mercure event fires for it (`TicketUpdatedEvent` deliberately not extended, per proposal.md's fixed constraint). The reopen is DB-correct and surfaces on the next list load. This is an accepted limitation for a future phase — do **not** add a new event emission to close this gap here.

## Phase 6 — Scheduled Closure Job (LAST)

*Per design.md's explicit rollout order: "`window-closer` last, after a `--dry-run` on production data." Do not schedule any part of this phase earlier.*

- [x] 6.1 RED: `ConversationWindowExpiryServiceTest` — `closeExpired()` closes only `status IN ('new','active','waiting','resolved') AND last_inbound_message_at IS NOT NULL AND last_inbound_message_at < threshold`; a `null` `last_inbound_message_at` never closes (D8 fail-open, mass-close mitigation for pre-existing tickets); `--dry-run` writes nothing; a per-ticket `\Throwable` is caught so one bad row cannot abort the batch.
- [x] 6.2 GREEN: implement `App\Application\Service\ConversationWindowExpiryService::closeExpired(bool $dryRun, int $limit): int` per design.md's query, calling `closeDueToWindowExpiry()` per id inside a per-ticket try/catch. New file `apps/api/src/Application/Service/ConversationWindowExpiryService.php`.
- [x] 6.3 RED: `CloseExpiredConversationWindowsCommandTest` — `--dry-run`, `--limit=200`, `--safety-margin=` options behave as documented; exit code reflects tickets processed.
- [x] 6.4 GREEN: implement `App\Command\CloseExpiredConversationWindowsCommand` (`#[AsCommand(name: 'app:conversation-window:close-expired')]`) as a thin wrapper delegating to the service — mirrors `OutboxPublishCommand`'s shape. New file `apps/api/src/Command/CloseExpiredConversationWindowsCommand.php`.
- [x] 6.5 Manual gate: run `php bin/console app:conversation-window:close-expired --dry-run` against production-like data and confirm the reported count is sane **before** enabling the loop — this is design.md's explicit rollout precondition, not optional.
- [x] 6.6 Add the `window-closer` service to `docker-compose.yml` **only after 6.5 is confirmed**: `sh -c "while true; do php bin/console app:conversation-window:close-expired; sleep 900; done"`, `restart: always`, `depends_on: [php]`. **Threat-matrix reminder**: the command takes no user-supplied arguments and no untrusted value reaches the shell string (identical shape to the shipped `publisher` service); a non-zero exit must not kill the loop — covered by 6.1's per-ticket `\Throwable` catch plus `restart: always`.
  - **Post-apply fix (2026-08-29, found during code review)**: the initial `sdd-apply` output added `window-closer` with no `profiles:` gate, so a routine `docker compose up -d` / `make up` would have silently started the recurring closure loop for the first time, bypassing the deliberate `--dry-run` review this task's own precondition requires. Added `profiles: [window-closer]`; verified live that `docker compose config --services` now excludes it and that running `docker compose up -d` leaves it stopped while every other service stays running undisturbed. Start it deliberately with `docker compose --profile window-closer up -d window-closer`.
- [x] 6.7 Confirm the rollback ordering (no new code): migration is additive/nullable (safe on a live table); the column starts `NULL` everywhere so `isExpiredForClosing()` closes nothing until inbound traffic backfills it; frontend is independently revertible; if a full rollback is ever needed, **stop `window-closer` first**, then revert code, then `down()` the migration together with the serializer/timestamps keys.

---

## Parallelization Notes

- Phase 0 is a hard sequential prerequisite for everything else.
- Within Phase 0: 0.1–0.7 (policy/entity/migration) and 0.8–0.9 (FSM) are independent of each other and can be done in either order, but both must land before Phase 1–3.
- Phase 1 (write-path guards) and Phase 2 (close path) are independent of each other once Phase 0 lands — both only need `ConversationWindowPolicy`/`TicketEventType`. They may be done in parallel or in either order.
- Phase 3 (reopen) depends on Phase 2's `TicketEventType::WINDOW_EXPIRED_CLOSED` existing (as data, not code) but not on Phase 1.
- Phase 4 (timeline) depends on both Phase 2 and Phase 3 having landed (needs both event type values to be meaningful).
- Phase 5's frontend tasks (5.3–5.7) can start as soon as Phase 0's migration/entity land, in parallel with Phase 1–4 backend work — the field is optional end-to-end until 5.1/5.2 wire the REST side.
- Phase 6 is strictly last, per design.md, regardless of what else is parallelized.

## Open Questions Closed by This Plan

- **Safety-margin default**: resolved in task 0.3 — `5` minutes, per spec.md's chosen default, plugged into the existing config binding (no new decision made here).
- **Reopen-visibility limitation (no live Mercure update)**: documented as an accepted limitation via task 5.9's code comment, not built around further. `TicketUpdatedEvent` stays unextended, per proposal.md's fixed constraint.
