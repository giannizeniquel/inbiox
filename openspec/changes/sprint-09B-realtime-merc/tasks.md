# Tasks: Sprint 9B Realtime with Mercure

## Phase Overview

| Phase | Focus | Files |
|-------|-------|-------|
| 1 | Foundation - entities, migrations | 4 |
| 2 | Core - domain events, event collector | 7 |
| 3 | Publishing -OutboxPublisher, Mercure | 2 |
| 4 | Security - JWT tokens | 1 |
| 5 | Integration - UseCases | 3 |
| 6 | Testing | 2 |

---

## Phase 1: Foundation

Packages, entities, migrations. Dependencies: none.

### T001: Install ULID Package

**Action**: `composer require symfonycasts/ulid`
**Verify**: `vendor/symfonycasts/ulid` exists

- [x] T001: Install ULID Package

### T002: Create OutboxEvent Entity

**File**: `src/Domain/Outbox/OutboxEvent.php`
**Details**:
- ULID primary key
- aggregate_id (bigint), topic, eventType, payload (json)
- published_at (datetime_immutable, nullable), retry_count
**Verify**: Entity loads in Symfony console

- [x] T002: Create OutboxEvent Entity

### T003: Create OutboxEventRepository

**Files**:
- `src/Domain/Outbox/OutboxEventRepository.php` (interface)
- `src/Repository/OutboxEventRepository.php` (Doctrine impl)
**Methods**: `save()`, `findUnpublished(int $limit)`, `markPublished(array $ids)`
**Verify**: Repository registered in DI container

- [x] T003: Create OutboxEventRepository

### T004: Create Migration

**File**: `migrations/Version2026xxxx_xxxxxx.php`
**Changes**:
- Create `outbox_events` table with ULID PK
- Add `last_event_seq` column to `tickets` table (default 0)
**Verify**: Migration runs without error

- [x] T004: Create Migration ✓ (incluye también T005)

### T005: Modify Ticket Entity

**File**: `src/Domain/Ticket/Ticket.php`
**Change**: Add `last_event_seq` field with getter/setter
**Verify**: Schema update detects new column

- [x] T005: Modify Ticket Entity

---

## Phase 2: Core

Domain events and event collection. Dependencies: Phase 1.

### T006: Create DomainEvent Base Class

**File**: `src/Domain/Event/DomainEvent.php`
**Properties**: eventId (ULID), type, ticketId, occurredAt, seq, version, payload
**Verify**: Class instantiates correctly

- [x] T006: Create DomainEvent Base Class

### T007: Create MessageCreatedEvent

**File**: `src/Domain/Event/MessageCreatedEvent.php`
**Extends**: DomainEvent
**Payload**: `['message_id' => int, 'direction' => 'inbound'|'outbound']`
**Verify**: Serializes to JSON correctly

- [x] T007: Create MessageCreatedEvent

### T008: Create TicketUpdatedEvent

**File**: `src/Domain/Event/TicketUpdatedEvent.php`
**Extends**: DomainEvent
**Payload**: `['status' => string, 'owner_agent_id' => int|null]`
**Verify**: Serializes to JSON correctly

- [x] T008: Create TicketUpdatedEvent

### T009: Create InboxUpdatedEvent

**File**: `src/Domain/Event/InboxUpdatedEvent.php`
**Extends**: DomainEvent
**Payload**: `['ticket_id' => int]`
**Verify**: Serializes to JSON correctly

- [x] T009: Create InboxUpdatedEvent

### T010: Create EventCollector Interface

**File**: `src/Domain/Event/EventCollector.php`
**Methods**: `collect(DomainEvent $event)`, `getEvents(): array`, `clear()`
**Verify**: Interface loads in container

- [x] T010: Create EventCollector Interface

### T011: Create DomainEventCollector Implementation

**File**: `src/Domain/Event/DefaultEventCollector.php`
**Implements**: EventCollector
**Behavior**: Stores events in array, converts to OutboxEvent on flush
**Verify**: Unit test passes

- [x] T011: Create DomainEventCollector Implementation

### T012: Create OutboxPass Dependency Injection

**File**: `src/DependencyInjection/OutboxPass.php`
**Registers**: EventCollector, OutboxEventRepository, OutboxPublisherService
**Verify**: Services available in container

- [x] T012: Create OutboxPass Dependency Injection

---

## Phase 3: Publishing

OutboxPublisherService and Mercure configuration. Dependencies: Phase 2.

### T013: Create OutboxPublisherService

**File**: `src/Application/Service/OutboxPublisherService.php`
**Behavior**:
- Query unpublished events (limit 100)
- Group by topic
- POST to Mercure hub (http://mercure/.well-known/mercure)
- Mark published on success
**Retry**: Max 5 attempts, increment retry_count on failure
**Verify**: Service instantiates with correct dependencies

### T014: Configure Mercure

**File**: `config/packages/mercure.yaml`
**Settings**:
- hub URL: http://localhost:3000/.well-known/mercure
- jwt secret from env (MERCURE_JWT_SECRET)
- publish also: true
**Verify**: Config loads without error

---

## Phase 4: Security

JWT token generation. Dependencies: Phase 3.

### T015: Create MercureJwtService

**File**: `src/Application/Service/MercureJwtService.php`
**Methods**:
- `generatePublisherToken()`: returns JWT with `mercure.subscribe: ['*']`
- `generateSubscriberToken(array $topics, int $agentId)`: returns JWT with specific topics
**JWT Library**: firebase/php-jwt
**Verify**: Token validates with Mercure hub

- [x] T015: Create MercureJwtService ✓

---

## Phase 5: Integration

UseCase modifications. Dependencies: Phase 4.

### T016: Modify HandleInboundMessageUseCase

**File**: `src/Application/UseCase/HandleInboundMessageUseCase.php`
**Changes**:
- Inject EventCollector
- After creating Message: `$collector->collect(new MessageCreatedEvent(...))`
- After creating OutboxEvent: use same transaction
**Verify**: Functional test creates events

- [x] T016: Modify HandleInboundMessageUseCase ✓

### T017: Modify AssignTicketUseCase

**File**: `src/Application/UseCase/AssignTicketUseCase.php`
**Changes**:
- Inject EventCollector
- On ticket assignment: `$collector->collect(new TicketUpdatedEvent(...))`
- Incrementticket.last_event_seq with optimistic locking
**Verify**: Functional test creates events

- [x] T017: Modify AssignTicketUseCase ✓

### T018: Modify CreateTicketUseCase (if exists)

**File**: `src/Application/UseCase/CreateTicketUseCase.php`
**Changes**:
- Inject EventCollector
- On ticket created: `$collector->collect(new TicketUpdatedEvent(...))`
**Verify**: Functional test creates events

- [x] T018: Modify CreateTicketUseCase (integrated in HandleInboundMessageUseCase) ✓

---

## Phase 6: Testing

Test coverage. Dependencies: Phases 1-5.

### T019: Unit Tests - OutboxEvent

**File**: `tests/Unit/Outbox/OutboxEventTest.php`
**Coverage**:
- Entity creation with ULID
- Payload serialization/deserialization
- PublishedAt setter
**Run**: `php bin/phpunit tests/Unit/Outbox/OutboxEventTest.php`

### T020: Unit Tests - Domain Events

**File**: `tests/Unit/Outbox/DomainEventTest.php`
**Coverage**:
- Event creation with correct payload
- Sequence numbering
- Serialization to OutboxEvent
**Run**: `php bin/phpunit tests/Unit/Outbox/DomainEventTest.php`

### T021: Integration Tests - OutboxPublisher

**File**: `tests/Integration/Outbox/OutboxPublisherTest.php`
**Coverage**:
- Publish unpublished events
- Mark published updates published_at
- Retry logic on failure
**Run**: `php bin/phpunit tests/Integration/Outbox/OutboxPublisherTest.php`

### T022: Integration Tests - MercureJwtService

**File**: `tests/Unit/Service/MercureJwtServiceTest.php`
**Coverage**:
- Publisher token has wildcard subscribe
- Subscriber token has correct topics
- Token expiration
**Run**: `php bin/phpunit tests/Unit/Service/MercureJwtServiceTest.php`

---

## Task Dependencies

```
T001 (ulid package)
   │
   ▼
T002 ──► T003 ──► T004 ──► T005
                  │            │
                  ▼            ▼
              T006 ◄──── T007 ◄──── T008 ◄──── T009 ◄──── T010
                                                           │
                                                           ▼
                                                      T011 ◄──── T012
                                                           │
                                                           ▼
                                                      T013 ◄──── T014
                                                           │
                                                           ▼
                                                      T015
                                                           │
                                                           ▼
                                      T016 ◄──── T017 ◄──── T018
                                                           │
                                                           ▼
                                              T019 ◄──── T020 ◄──── T021 ◄──── T022
```

---

## Acceptance Criteria

- [ ] OutboxEvent persisted in same transaction as business operation
- [ ] Events published to Mercure after commit
- [ ] Sequence numbers per ticket are monotonic
- [ ] JWT tokens authorize by topic correctly
- [ ] UseCases emit appropriate domain events
- [ ] Tests pass with >80% coverage on new code

---

## Notes

- Open questions from design remain unresolved and are out of scope:
  - Publishing strategy: use sync approach for Sprint 9B
  - Retry backoff: defaultMercure retry
  - Event retention: not implemented
- Mercure hub runs on port 3000 in docker-compose