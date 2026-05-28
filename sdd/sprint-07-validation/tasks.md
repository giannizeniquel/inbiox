# Tasks: Sprint 07 Validation

## 1. Infrastructure (migration, entity changes)

### Task: Create ProcessedEvent entity for idempotent tracking

#### Type
infrastructure

#### Files
- `apps/api/src/Entity/ProcessedEvent.php` — New entity to track processed event IDs with unique constraint on `event_id`

#### Steps
1. Create `ProcessedEvent` class with `id`, `eventId` (unique string), `processedAt` (DateTimeImmutable) properties
2. Add Doctrine ORM annotations: `@Entity`, `@Table`, `@UniqueConstraint(columns: ["event_id"])`
3. Generate getters/setters for all properties

#### Test Scenario
Idempotent Event Processing Scenario 1: Duplicate events only processed once

---

### Task: Generate migration for ProcessedEvent table

#### Type
infrastructure

#### Files
- `apps/api/src/Migrations/Version20260503000000.php` — Doctrine migration to create `processed_event` table

#### Steps
1. Run `php bin/console doctrine:migrations:diff` to generate migration for `ProcessedEvent` entity
2. Verify migration creates `id`, `event_id` (unique), `processed_at` columns
3. Add migration to version control

#### Test Scenario
Idempotent Event Processing Scenario 1: Duplicate events only processed once

---

### Task: Add optimistic locking version field to Ticket entity

#### Type
infrastructure

#### Files
- `apps/api/src/Entity/Ticket.php` — Existing Ticket entity, add version field for optimistic locking

#### Steps
1. Add private `?int $version` property with `@ORM\Version()` annotation
2. Generate getter for `version` field
3. Ensure `@ORM\Entity` listener is configured for version incrementing

#### Test Scenario
Optimistic Locking Scenario 1: Update with stale version returns 409 Conflict

---

### Task: Add last activity field to Ticket entity for AutoClose

#### Type
infrastructure

#### Files
- `apps/api/src/Entity/Ticket.php` — Add `lastActivityAt` field to track ticket activity
- `apps/api/src/Migrations/Version20260503000001.php` — Migration to add `last_activity_at` column to `ticket` table

#### Steps
1. Add private `?DateTimeImmutable $lastActivityAt` property with `@ORM\Column(type: datetime_immutable, nullable: true)`
2. Generate getter/setter for `lastActivityAt`
3. Create and verify migration for new column

#### Test Scenario
AutoClose Activity Check Scenario 1: Ticket with recent activity is not auto-closed

---

### Task: Add SLA timestamp fields to Ticket entity

#### Type
infrastructure

#### Files
- `apps/api/src/Entity/Ticket.php` — Add `slaFirstResponseAt` and `slaResolutionAt` fields
- `apps/api/src/Migrations/Version20260503000002.php` — Migration to add SLA columns to `ticket` table

#### Steps
1. Add private `?DateTimeImmutable $slaFirstResponseAt` and `$slaResolutionAt` with nullable `datetime_immutable` columns
2. Generate getters/setters with guard logic (only set if not already set)
3. Create and verify migration for new columns

#### Test Scenario
SLA Timestamp Determinism Scenario 1: `slaFirstResponseAt` only set on first agent reply

## 2. Idempotent Event Processing Feature

### Task: Implement idempotent event handler

#### Type
feature

#### Files
- `apps/api/src/EventSubscriber/IdempotentEventHandler.php` — Event subscriber to check processed events before processing
- `apps/api/src/Repository/ProcessedEventRepository.php` — Repository with `exists(string $eventId): bool` method

#### Steps
1. Create `ProcessedEventRepository` with `findByEventId` and `save` methods
2. Create `IdempotentEventHandler` that intercepts all domain events
3. On event receive: check if event ID exists in `ProcessedEvent`, skip if exists
4. Process event then persist `ProcessedEvent` record

#### Test Scenario
Idempotent Event Processing Scenario 2: New unique event processed successfully

---

### Task: Write unit tests for idempotent event handler

#### Type
test

#### Files
- `apps/api/tests/Unit/EventSubscriber/IdempotentEventHandlerTest.php` — Unit tests for idempotent logic

#### Steps
1. Mock `ProcessedEventRepository` to simulate exists/non-exists states
2. Test duplicate events are skipped, new events are processed and recorded
3. Test concurrent duplicate handling with mock time delays

#### Test Scenario
Idempotent Event Processing Scenario 3: Concurrent duplicates only processed once

## 3. Optimistic Locking Feature

### Task: Implement optimistic lock exception handling

#### Type
feature

#### Files
- `apps/api/src/UseCase/Ticket/UpdateTicketUseCase.php` — Wrap ticket updates in optimistic lock try/catch
- `apps/api/src/Controller/Ticket/TicketController.php` — Return 409 on `OptimisticLockException`

#### Steps
1. Add version parameter to `UpdateTicketRequest` DTO
2. Catch `Doctrine\ORM\OptimisticLockException` in update use case
3. Return 409 HTTP status with latest version number on lock failure

#### Test Scenario
Optimistic Locking Scenario 2: Update with correct version succeeds

---

### Task: Write unit tests for optimistic locking

#### Type
test

#### Files
- `apps/api/tests/Unit/UseCase/Ticket/UpdateTicketUseCaseTest.php` — Tests for lock handling

#### Steps
1. Mock Ticket repository to throw `OptimisticLockException` on save
2. Test 409 response is returned for stale versions
3. Test successful update with matching version

#### Test Scenario
Optimistic Locking Scenario 3: Concurrent updates return 409 for second request

## 4. AutoClose Fix

### Task: Implement ticket activity check for AutoClose

#### Type
feature

#### Files
- `apps/api/src/UseCase/Ticket/AutoCloseTicketsUseCase.php` — Update to use `lastActivityAt` instead of broken check
- `apps/api/src/Service/TicketActivityService.php` — New service to check ticket activity against threshold

#### Steps
1. Create `TicketActivityService` with `isActive(Ticket $ticket): bool` method (check `lastActivityAt > now() - threshold`)
2. Configure inactivity threshold via `INBIOX_TICKET_INACTIVE_DAYS` env var (default 7)
3. Update `AutoCloseTicketsUseCase` to use `TicketActivityService` to filter tickets

#### Test Scenario
AutoClose Activity Check Scenario 2: Ticket with no activity beyond threshold is auto-closed

---

### Task: Update Ticket Workflow state machine

#### Type
refactor

#### Files
- `apps/api/src/StateMachine/TicketWorkflowStateMachine.php` — Update FSM transitions to match modified spec

#### Steps
1. Adjust state transitions to match 3 modified Ticket Workflow scenarios
2. Add guard clauses to prevent invalid transitions
3. Ensure all transitions update `lastActivityAt` on the Ticket

#### Test Scenario
Ticket Workflow Scenario 1: Valid transition succeeds, invalid returns 400

---

### Task: Implement deterministic SLA timestamp setting

#### Type
feature

#### Files
- `apps/api/src/Service/SlaService.php` — Service to set SLA timestamps deterministically
- `apps/api/src/EventSubscriber/TicketSlaSubscriber.php` — Event subscriber to trigger SLA updates

#### Steps
1. Create `SlaService` with `setSlaTimestamps(Ticket $ticket, string $trigger)` method
2. Only set `slaFirstResponseAt` on first agent reply, `slaResolutionAt` on resolve transition
3. Use event timestamps instead of current time for SLA values

#### Test Scenario
SLA Timestamp Determinism Scenario 2: `slaResolutionAt` only set on resolve transition

---

### Task: Write unit tests for AutoClose and SLA logic

#### Type
test

#### Files
- `apps/api/tests/Unit/UseCase/Ticket/AutoCloseTicketsUseCaseTest.php` — AutoClose tests
- `apps/api/tests/Unit/Service/SlaServiceTest.php` — SLA determinism tests

#### Steps
1. Test AutoClose with tickets at different activity thresholds
2. Test SLA timestamps are not overwritten on subsequent actions
3. Test SLA timestamps use event time, not current time

#### Test Scenario
AutoClose Activity Check Scenario 3: Activity after threshold reset prevents auto-close
SLA Timestamp Determinism Scenario 3: No SLA change on subsequent actions

## 5. Integration Tests

### Task: Integration test idempotent event processing

#### Type
test

#### Files
- `apps/api/tests/Integration/EventSubscriber/IdempotentEventHandlerIntegrationTest.php` — E2E test with real DB

#### Steps
1. Dispatch duplicate domain events via test client
2. Verify only one `ProcessedEvent` entry exists in database
3. Verify no duplicate side effects (e.g., single notification sent)

#### Test Scenario
Integration Test Scenario 1: Idempotent event processing E2E

---

### Task: Integration test optimistic locking

#### Type
test

#### Files
- `apps/api/tests/Integration/Controller/Ticket/TicketControllerIntegrationTest.php` — E2E ticket update test

#### Steps
1. Create ticket, retrieve current version
2. Send two concurrent update requests with stale version
3. Verify first succeeds, second returns 409

#### Test Scenario
Integration Test Scenario 2: Optimistic locking E2E

---

### Task: Integration test AutoClose workflow

#### Type
test

#### Files
- `apps/api/tests/Integration/UseCase/Ticket/AutoCloseTicketsIntegrationTest.php` — E2E auto-close test

#### Steps
1. Create tickets with varying `lastActivityAt` values
2. Run `AutoCloseTicketsUseCase`
3. Verify only inactive tickets are closed in database

#### Test Scenario
Integration Test Scenario 3: AutoClose E2E

---

### Task: Integration test SLA timestamp determinism

#### Type
test

#### Files
- `apps/api/tests/Integration/Service/SlaServiceIntegrationTest.php` — E2E SLA test

#### Steps
1. Create ticket, send first agent response
2. Verify `slaFirstResponseAt` matches response event time
3. Send second response, verify `slaFirstResponseAt` unchanged

#### Test Scenario
Integration Test Scenario 4: SLA timestamp determinism E2E
