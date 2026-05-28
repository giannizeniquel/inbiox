# Exploration: Sprint 9B Realtime with Mercure

### Current State

**Mercure Infrastructure - READY:**
- docker-compose.yml already has Mercure service configured (lines 43-54)
- Container `inbiox_mercure` running on port 3000
- JWT keys configured (both publisher and subscriber use same secret for dev)
- CORS configured for `http://localhost:4200` (Angular)
- Anonymous mode enabled for local dev

**Backend Code - NOTHING IMPLEMENTED:**
- No Mercure Symfony packages installed (composer.json)
- No Mercure bundle in config/packages/
- No OutboxEvent entity
- No OutboxPublisherService
- No JWT auth for agents
- No Domain Events for realtime

**Existing Domain Entities:**
- Ticket (has `assignedAgentId`, SLA timestamps, version for optimistic locking)
- Agent (name, email, active, isAvailable, maxActiveTickets, createdAt, updatedAt)
- Contact, Conversation, Message, Assignment, Tag

**UseCase Pattern - Transactional:**
- All UseCases use `$em->getConnection()->transactional()` pattern (good foundation)
- Events dispatched via Symfony EventDispatcher (TicketNeedsAutoAssignmentEvent exists)
- No outbox pattern - events dispatched AFTER flush in HandleInboundMessageUseCase

**Missing Critical:**
- Ticket.last_event_seq field (required for sequence guarantee)
- OutboxEvent entity with ULID, aggregate_id, topic, event_type, payload, published_at, retry_count
- Outbox table creation and migration
- MercureBundle installation
- JWT library for Mercure tokens
- Domain event classes (message.created, ticket.updated, inbox.updated)

### Affected Areas

- `docker-compose.yml` — Mercure already running, no changes needed
- `apps/api/composer.json` — Need to add: symfony/mercure-bundle, lcobucci/jwt
- `apps/api/src/Domain/Ticket/Ticket.php` — Add last_event_seq field (migration required)
- `apps/api/src/Domain/` — Create OutboxEvent entity
- `apps/api/src/Application/UseCase/*.php` — Integrate domain event collection
- `apps/api/src/Security/` or similar — Create JWT generator for agents
- New service — OutboxPublisherService (async worker)

### Approaches

1. **Full Outbox with Async Publisher**
   - Create OutboxEvent entity
   - Create domain event classes
   - UseCases push events to Outbox in same transaction
   - Async command (cron/worker) publishes to Mercure
   - Pros: Resilient, eventual consistency, retries built-in
   - Cons: More complex, needs worker process
   - Effort: High

2. **Synchronous Direct Publish**
   - Skip outbox, publish directly after commit
   - Pros: Simpler, no additional infrastructure
   - Cons: No resilience on Mercure failure, blocks request
   - Effort: Medium
   - **NOT RECOMMENDED** - violates Sprint 9B requirements

3. **Outbox with Immediate Publish (Same Request)**
   - Outbox insert in transaction
   - Immediately after commit, publish from same request
   - Async retry if fails
   - Pros: Simple + some resilience
   - Cons: Still ties to request lifecycle
   - Effort: Medium

### Recommendation

**Full Outbox Pattern (Approach #1)** is the ONLY correct choice based on Sprint 9B requirements:
- Outbox MANDATORY
- Events as side effects of committed transactions
- Resilient to retries and failures
- Sequence guarantee per ticket
- Idempotent events

Implementation order:
1. Add MercureBundle + JWT library to composer.json
2. Create OutboxEvent entity with migration
3. Add last_event_seq to Ticket entity with migration
4. Create domain event classes in Domain/Event/
5. Create OutboxPublisherService
6. Update UseCases to collect + write events (same transaction)
7. Create JWT token generator for agent subscriptions
8. Create async worker or cron for publishing

### Risks

- **Transaction Coupling**: Events MUST be in same transaction as business logic - existing UseCases need refactoring
- **Sequence Atomicity**: `last_event_seq` increment must be atomic with event write - requires `SELECT FOR UPDATE`
- **JWT Security**: Need to generate per-agent tokens - must verify agent has access to topic
- **Mercure Failure**: Publishing failure must not break main flow - outbox handles retries
- **No Prior Outbox**: First implementation - team must learn pattern

### Ready for Proposal

**Yes** - Ready for sdd-propose.

Create proposal for full outbox implementation following Sprint 9B spec. Include:
- Scope: OutboxEvent entity, Ticket.last_event_seq, Domain events, OutboxPublisherService, JWT auth, UseCase integrations
- Approach: Outbox pattern with async publisher
- Dependencies: symfony/mercure-bundle, lcobucci/jwt
- Migration complexity: Yes - adding two new columns

Orchestrator should tell user: Mercure is running in Docker, but full backend implementation needed including new entities, packages, and event system.