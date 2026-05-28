# Proposal: Sprint 9B Realtime with Mercure

## Intent

Implement a reliable, event-driven realtime layer using Mercure (SSE) with the **outbox pattern** as the mandatory foundation. This enables Angular clients to receive live updates about tickets, messages, and agent inboxes while maintaining REST API as the single source of truth.

## Scope

### In Scope

- OutboxEvent entity with ULID, aggregate_id, topic, event_type, payload, published_at, retry_count
- Ticket.last_event_seq field for sequence guarantee per ticket
- Domain event classes: message.created, ticket.updated, inbox.updated
- Event → Outbox mapper service
- OutboxPublisherService (async-safe publishing to Mercure)
- MercureBundle integration in Symfony
- JWT topic security for agent subscriptions
- UseCase integrations (HandleInboundMessageUseCase, HandleOutboundMessageUseCase, ChangeTicketStatusUseCase, AssignTicketUseCase)
- PHPUnit tests for outbox publishing and sequence guarantee

### Out of Scope

- Frontend Angular SSE consumer implementation (future sprint)
- Real-time chat UI components
- Push notifications outside Mercure
- WebSocket fallback (use Mercure only)

## Capabilities

### New Capabilities

- `outbox-event-entity`: Entity for persisting domain events before publishing
- `ticket-sequence`: Ticket.last_event_seq for ordered event delivery per ticket
- `domain-events`: Domain event classes with idempotent structure
- `outbox-publisher`: Async service to publish outbox events to Mercure
- `mercure-jwt-security`: JWT-based authorization for agent topics
- `usecase-event-integration`: UseCases collect and persist events in same transaction

### Modified Capabilities

- `ticket-entity`: Add last_event_seq field (migration required)

## Approach

**Full Outbox Pattern with Async Publisher**:

1. UseCases collect domain events in-memory during transaction
2. Events written to OutboxEvent table in same DB transaction as business logic
3. After commit, OutboxPublisherService picks up unpublished events
4. Publishes to Mercure with ULID event_id and seq for idempotency
5. On success: mark published_at; on failure: increment retry_count

Critical: Outbox INSERT MUST be in the same transaction as the business change. If transaction fails → NO event stored.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/composer.json` | Modified | Add symfony/mercure-bundle, lcobucci/jwt |
| `apps/api/src/Domain/Ticket/Ticket.php` | Modified | Add last_event_seq field |
| `apps/api/src/Domain/OutboxEvent/` | New | OutboxEvent entity |
| `apps/api/src/Domain/Event/` | New | Domain event classes |
| `apps/api/src/Application/OutboxMapper/` | New | Event → Outbox mapper |
| `apps/api/src/Application/OutboxPublisher/` | New | Publishing service |
| `apps/api/src/Application/UseCase/*` | Modified | Integrate event collection |
| `apps/api/config/packages/mercure.yaml` | New | Mercure configuration |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Transaction coupling: events in wrong transaction | Medium | Use `$em->getConnection()->transactional()` + event flush before commit |
| Sequence race condition | Medium | Use SELECT FOR UPDATE on ticket before incrementing |
| JWT security misconfiguration | High | Test topic access denial before deployment |
| Mercure failure breaks flow | Low | Outbox pattern handles retries; never block main request |

## Rollback Plan

1. Revert composer.json changes: remove MercureBundle and JWT library
2. Rollback migration: remove last_event_seq column from tickets table
3. Rollback migration: drop outbox_event table
4. Remove OutboxEvent entity class and mapper
5. Revert UseCase changes to remove event collection calls
6. Re-install dependencies: `composer install`

## Dependencies

- **External**: Mercure hub (already running in Docker on port 3000)
- **Composer**: symfony/mercure-bundle, lcobucci/jwt
- **Database**: Requires migration for outbox_event table and last_event_seq column

## Success Criteria

- [ ] OutboxEvent entity persists events in same transaction as business logic
- [ ] Ticket.last_event_seq increments atomically (no race conditions)
- [ ] Events published to Mercure after commit only
- [ ] Per-ticket ordering guaranteed via seq field
- [ ] Events are idempotent (ULID event_id)
- [ ] Agent can only subscribe to assigned tickets and own inbox topic
- [ ] Failed publishes retry via outbox
- [ ] All 8 mandatory test cases pass