# Design: Sprint 9B Realtime with Mercure

## Technical Approach

Implement the transactional outbox pattern to provide reliable real-time event delivery via Mercure. UseCases collect domain events in the same database transaction as business operations, then OutboxPublisherService publishes after commit. Sequence numbers ensure per-ticket ordering guarantees.

## Architecture Decisions

### Decision: ULID for Event IDs

**Choice**: Use `symfonycasts/ulid` for OutboxEvent primary keys
**Alternatives considered**: UUID v7 (time-ordered), auto-increment integers
**Rationale**: ULIDs provide sortability, uniqueness, and short representation (26 chars). Required by Mercure JWT spec for event_id uniqueness.

### Decision: Domain Event Collection Pattern

**Choice**: UseCases receive an `EventCollector` interface to collect events during transaction
**Alternatives considered**: Domain events dispatched via Symfony EventDispatcher, then mapper reads dispatched events
**Rationale**: Explicit collection gives UseCases control over event timing and payload. Keeps business logic testable without mocking an event bus.

### Decision: Sequence Atomicity via Optimistic Locking

**Choice**: Use `last_event_seq` + `version` field together for atomic sequence increment
**Alternatives considered**: Pessimistic locking with SELECT FOR UPDATE, database sequences
**Rationale**: Ticket already has `version` field for optimistic locking. Adding `last_event_seq` provides ordering per ticket while leveraging existing concurrency control.

### Decision: OutboxEvent Written in Same Transaction

**Choice**: Persist OutboxEvent in the same transaction as the business operation
**Alternatives considered**: Publish first, then write to outbox (dual-write), async outbox with separate process
**Rationale**: Same-transaction guarantees no events lost if transaction fails. After commit, publisher processes unpublished events.

### Decision: Mercure JWT with Topic Authorization

**Choice**: Embed topic array in JWT `mercure.subscribe` claim
**Alternatives considered**: Webhook authorization, API key per topic
**Rationale**: Mercure natively supports JWT topic authorization. Publisher JWT has full access, subscriber JWTs restrict to assigned ticket topics.

## Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                        UseCase Execution                         │
│  ┌─────────────┐    ┌─────────────┐    ┌───────────────────┐    │
│  │ Business   │    │ Event      │    │ OutboxEvent       │    │
│  │ Logic      │───▶│ Collector │───▶│ (same transaction)│    │
│  └─────────────┘    └─────────────┘    └───────────────────┘    │
│         │                                       │                │
│         │              Transaction               │                │
│         └───────────────────────────────────────┘                │
│                              │                                 │
│                         commit()                               │
│                              ▼                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  ���              OutboxPublisherService                    │    │
│  │  ┌─────────────┐    ┌─────────────┐                   │    │
│  │  │ Query      │───▶│ Publish    │───▶ Mercure Hub    │    │
│  │  │ unpublished│    │ to SSE     │    (port 3000)    │    │
│  │  └─────────────┘    └─────────────┘                   │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘

Sequence flow per ticket:
  Ticket.last_event_seq = 0 (new ticket)
  ┌──────────────────────────────────────────────┐
  │ HandleInboundMessage(ticket)                   │
  │ 1. SELECT version FROM tickets WHERE id = ?   │
  │ 2. Increment last_event_seq (version check)   │
  │ 3. Create Message + OutboxEvent            │
  │ 4. Flush transaction                    │
  └──────────────────────────────────────────────┘
                              │
                              ▼
  Ticket.last_event_seq = 1
  Event seq = 1, topic = "ticket/{id}"
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/Domain/Outbox/OutboxEvent.php` | Create | OutboxEvent entity with ULID PK |
| `src/Domain/Outbox/OutboxEventRepository.php` | Create | Repository interface |
| `src/Repository/OutboxEventRepository.php` | Create | Doctrine repository |
| `src/Domain/Event/DomainEvent.php` | Create | Base domain event class |
| `src/Domain/Event/MessageCreatedEvent.php` | Create | message.created event |
| `src/Domain/Event/TicketUpdatedEvent.php` | Create | ticket.updated event |
| `src/Domain/Event/InboxUpdatedEvent.php` | Create | inbox.updated event |
| `src/Application/Event/EventCollector.php` | Create | Interface for collecting events |
| `src/Application/Event/DomainEventCollector.php` | Create | Default implementation |
| `src/Application/Service/OutboxPublisherService.php` | Create | Publishes unpublished events |
| `src/Application/Service/MercureJwtService.php` | Create | Generates JWT tokens |
| `src/Domain/Ticket/Ticket.php` | Modify | Add `last_event_seq` field |
| `migrations/Version2026xxxx_xxxxxx.php` | Create | Add outbox_events table, last_event_seq column |
| `src/Application/UseCase/HandleInboundMessageUseCase.php` | Modify | Collect domain events |
| `src/Application/UseCase/AssignTicketUseCase.php` | Modify | Collect domain events |
| `src/DependencyInjection/OutboxPass.php` | Create | Register outbox services |
| `config/packages/mercure.yaml` | Create | Mercure configuration |
| `tests/Unit/OutboxEventTest.php` | Create | Unit tests |
| `tests/Integration/OutboxPublisherTest.php` | Create | Integration tests |

## Domain Event Structure

```php
// Base structure (all events)
readonly class DomainEvent
{
    public function __construct(
        public readonly string $eventId,        // ULID
        public readonly string $type,           // message.created | ticket.updated | inbox.updated
        public readonly int $ticketId,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly int $seq,           // last_event_seq + 1
        public readonly int $version = 1,
        public readonly array $payload = []
    ) {}
}

// MessageCreated payload
['message_id' => int, 'direction' => 'inbound'|'outbound']

// TicketUpdated payload  
['status' => string, 'owner_agent_id' => int|null]

// InboxUpdated payload
['ticket_id' => int]
```

## OutboxEvent Entity

```php
#[ORM\Entity]
#[ORM\Table(name: 'outbox_events')]
class OutboxEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;  // ULID

    #[ORM\Column(name: 'aggregate_id', type: 'bigint')]
    private int $aggregateId;

    #[ORM\Column(type: 'string')]
    private string $topic;  // ticket/123 or agent/456/inbox

    #[ORM\Column(type: 'string')]
    private string $eventType;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'retry_count', type: 'integer')]
    private int $retryCount = 0;
}
```

## Mercure JWT Structure

```php
// Publisher JWT (server-side)
[
    'mercure' => ['subscribe' => ['*']],  // All topics
    'exp' => time() + 3600
]

// Subscriber JWT (agent-specific)
[
    'mercure' => [
        'subscribe' => [
            'ticket/123',
            'ticket/456', 
            'agent/7/inbox'
        ]
    ],
    'agent_id' => 7,
    'exp' => time() + 3600
]
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|---------|
| Unit | OutboxEvent entity creation, sequence increment | Mock EntityManager, test collector |
| Unit | Domain event serialization | Test payload structure |
| Unit | MercureJwtService token generation | Mock JWT signer, verify claims |
| Integration | Same-transaction outbox write | InMemory EntityManager |
| Integration | OutboxPublisherService flow | Test DB + mock Mercure HTTP |
| Integration | UseCase emits events | Functional test with real repositories |

## Migration / Rollout

No migration required. New fields initialize with defaults:
- `last_event_seq`: 0 on new tickets
- `outbox_events`: Empty table for new events

Feature flag: None required. Outbox pattern degrades gracefully if publisher fails (events remain in table for retry).

## Open Questions

- [ ] **Publishing strategy**: Should OutboxPublisherService run as a Message Consumer (async) or synchronous after each commit? **Recommendation**: Async via Messenger for throughput, with sync option for immediate delivery.
- [ ] **Retry backoff**: Exponential backoff for failed publishes? **Recommendation**: Use Mercure's built-in retry, max 5 attempts.
- [ ] **Event retention**: Archive/purge published events after X days? **Recommendation**: Separate archiver job, not in Sprint 9B.