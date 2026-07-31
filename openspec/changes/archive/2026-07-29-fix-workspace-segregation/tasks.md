# Tasks: Fix Workspace Segregation in Inbound Messages

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~50–70 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

## Phase 1: Interface Contracts

Add `?string $phoneNumberId = null` parameter to repository interfaces. Default `null` preserves backward compatibility.

- [ ] 1.1 Add `?string $phoneNumberId = null` param to `ConversationRepositoryInterface::findOpenByContact()` — file: `apps/api/src/Domain/Conversation/ConversationRepositoryInterface.php` line 9
- [ ] 1.2 Add `?string $phoneNumberId = null` param to `TicketRepositoryInterface::findOpenByContact()` and `findNotClosedByContact()` — file: `apps/api/src/Domain/Ticket/TicketRepositoryInterface.php` lines 10–11

## Phase 2: Repository Implementations

Update implementations to accept the new parameter and filter by `phoneNumberId` when non-null.

- [ ] 2.1 Update `ConversationRepository::findOpenByContact()` — add `?string $phoneNumberId = null` param, conditionally add `'phoneNumberId' => $phoneNumberId` to `findOneBy()` criteria array — file: `apps/api/src/Repository/ConversationRepository.php` lines 18–21
- [ ] 2.2 Rewrite `TicketRepository::findOpenByContact()` — switch from `findOneBy()` to `createQueryBuilder('t')`, add `?string $phoneNumberId = null` param, conditionally add `andWhere('t.phoneNumberId = :phoneNumberId')` — file: `apps/api/src/Repository/TicketRepository.php` lines 20–23. Use `TicketStatus` enum for status values.
- [ ] 2.3 Rewrite `TicketRepository::findNotClosedByContact()` — same QueryBuilder pattern as 2.2, statuses include `TicketStatus::RESOLVED->value` — file: `apps/api/src/Repository/TicketRepository.php` lines 25–28

## Phase 3: Use Case Wiring

Thread `$phoneNumberId` from `executeInternal()` into the two repository calls.

- [ ] 3.1 Update `HandleInboundMessageUseCase::executeInternal()` line 156 — change `findOpenByContact($contact)` to `findOpenByContact($contact, $phoneNumberId)` — file: `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php`
- [ ] 3.2 Update `HandleInboundMessageUseCase::executeInternal()` line 196 — change `findNotClosedByContact($contact)` to `findNotClosedByContact($contact, $phoneNumberId)` — file: `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php`

## Phase 4: Tests

Add unit tests covering the spec scenarios (SC-1 through SC-6).

- [ ] 4.1 Add test `testDifferentWorkspaceCreatesNewConversationAndTicket` — mock `findOpenByContact($contact, 'B')` returns `null`, mock `findNotClosedByContact($contact, 'B')` returns `null`, assert new Conversation + Ticket persisted with `phoneNumberId = 'B'` — file: `apps/api/tests/Unit/HandleInboundMessageUseCaseTest.php`
- [ ] 4.2 Add test `testSameWorkspaceReusesExistingConversationAndTicket` — mock `findOpenByContact($contact, 'A')` returns existing conversation, mock `findNotClosedByContact($contact, 'A')` returns existing ticket, assert no new entities persisted — file: `apps/api/tests/Unit/HandleInboundMessageUseCaseTest.php`
- [ ] 4.3 Add test `testNullPhoneNumberIdPreservesLegacyBehavior` — mock `findOpenByContact($contact, null)` returns existing conversation (no workspace filter), assert reuse — file: `apps/api/tests/Unit/HandleInboundMessageUseCaseTest.php`

## Phase 5: Verification

- [ ] 5.1 Run `docker exec chatapp_php vendor/bin/phpunit` — all tests pass (existing + new)
- [ ] 5.2 Run `docker exec chatapp_php vendor/bin/phpstan analyse` — no type errors from signature changes
