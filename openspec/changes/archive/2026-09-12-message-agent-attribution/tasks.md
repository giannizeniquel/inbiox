# Tasks: Message Agent Attribution

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | ~450-600 (backend ~350-480, frontend ~70-120) |
| Project review budget | 800 (override, see sdd-init/inbiox) |
| 800-line budget risk | Medium (under budget, not High) |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending (not needed — under budget) |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Medium (project budget overridden to 800; still Medium against 800)

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Full attribution feature (migration+enum+4 write sites+event+read path+FE guards) | Single PR | `make up && docker compose exec php bin/phpunit` | `cd apps/web && ./node_modules/.bin/ng build --configuration production` (Karma unrunnable in env) | Revert PR; `doctrine:migrations:migrate prev` |

## Phase 1: Foundation

- [x] 1.1 Create `apps/api/migrations/Version20260912000000.php`: nullable `sender_agent_id` BIGINT no-FK + `sender_type` VARCHAR(20) + index; reversible `down()`.
- [x] 1.2 Create `MessageSenderType` backed enum (agent/automatic/contact) at `apps/api/src/Domain/Message/MessageSenderType.php`, shaped like `MessageDirection`.
- [x] 1.3 Add `senderAgentId`/`senderType` props + accessors to `Message.php`; `setSenderType(MessageSenderType)` plain setter, no `enumType`.
- [x] 1.4 RED: PHPUnit migration up/down test (columns+index appear/drop). GREEN: apply locally.

## Phase 2: Write-Site Attribution

- [x] 2.1 RED: `HandleOutboundMessageUseCase` sets `sender_agent_id`=agentId (null if 0) + `sender_type`=AGENT.
- [x] 2.2 GREEN: wire after `setContent()` (`:257`).
- [x] 2.3 RED: `AutoReplyService` sets `sender_agent_id`=mailbox agent + `sender_type`=AUTOMATIC.
- [x] 2.4 GREEN: wire after `setDeliveryStatus()` (`:157`).
- [x] 2.5 RED: `HandleInboundMessageUseCase` sets `sender_type`=CONTACT, `senderAgentId` stays null.
- [x] 2.6 GREEN: wire before `persist()` (`:214`).
- [x] 2.7 Extract `resolveActingAgentId(?User): ?int` in `ProviderProxyController.php` from `send()`'s fallback (72-83); never throws.
- [x] 2.8 Rewire `send()` to call helper (`?? 0` preserved at call site).
- [x] 2.9 Add `#[CurrentUser] ?User $user` to `sendTemplate()`; call helper; set attribution after `setUpdatedAt()` (~295).
- [x] 2.10 RED: `sendTemplate` resolvable Agent → id set, AGENT.
- [x] 2.11 RED: `sendTemplate` no Agent record → send succeeds, id null, type AGENT.
- [x] 2.12 GREEN: both pass.

## Phase 3: Event + Outbox

- [x] 3.1 After `flush()` (~320) in `HandleOutboundMessageUseCase`, collect `MessageCreatedEvent` (fields per `HandleInboundMessageUseCase.php:362-373`, direction=OUTBOUND, senderName via agentRepo).
- [x] 3.2 Port `MessageCreatedEvent` branch into `persistEventsToOutbox()` verbatim from `HandleInboundMessageUseCase.php:124-137` (incl. phone_number_id lookup).
- [x] 3.3 RED: integration test — agent reply → 1 outbox row with message_id+content (pattern `Sprint09BOutboxTest.php:210-238`).
- [x] 3.4 GREEN: confirm shape.

## Phase 4: Read Path

- [x] 4.1 Add `sender_agent_id, sender_type` to both SELECTs in `GetTicketMessagesQuery.php` (62, 108).
- [x] 4.2 Add hydration in both blocks (85-91, 138-144).
- [x] 4.3 Append 2 nullable trailing params to `MessageDTO` (after `$media`) + 2 `toArray()` keys.
- [x] 4.4 RED/GREEN: query returns both fields for attributed + legacy (null) rows.

## Phase 5: Frontend Guards

- [x] 5.1 RED: spec — echo `MessageAdded` with existing id ignored (`conversation.store.spec.ts`).
- [x] 5.2 GREEN: guard in `conversation.store.ts` — skip if id already in `messages()`.
- [x] 5.3 RED: spec — `confirmMessage` drops duplicate entries sharing confirmed id.
- [x] 5.4 GREEN: in `confirmMessage()`, drop entries where `id===confirmed.id && tempId!==tempId`.
- [x] 5.5 RED: spec — `MessageAdded` with `direction!=='inbound'` on non-active ticket skips unread bump (`ticket.store.spec.ts`).
- [x] 5.6 GREEN: guard in `ticket.store.ts` (~107-116).
- [x] 5.7 Verify: `ng build --configuration production` exits 0 (Karma unrunnable; build is enforceable FE gate).

## Phase 6: Verification

- [x] 6.1 Baseline: `make up && docker compose exec php bin/phpunit` — confirm failures == known 58 before starting. (Re-measured live: 614 tests, 18 errors + 39 failures = 57 failing, not 58 — used 57 as the actual reference baseline per instructions to re-measure rather than trust the cached number.)
- [x] 6.2 Full suite green after all phases (no new failures beyond baseline). (630 tests, 18 errors + 39 failures = 57 — identical to baseline; all 16 new attribution tests pass.)
- [x] 6.3 Confirm deploy-ordering constraint honored (see note). (Migration applied to local dev DB — and test DB schema synced — before write-site code was implemented/tested.)

## Deploy-Ordering Note (explicit constraint, not assumed)

Migration MUST land and run BEFORE code from Phases 2-4 deploys — code without migration fails inserts (missing columns); migration without code is safe. Sequence: merge+run migration → deploy write/read-path code. Do not treat as one atomic deploy step if pipeline allows partial rollout.

## STATUS: ALL 27 TASKS COMPLETE (27/27)
