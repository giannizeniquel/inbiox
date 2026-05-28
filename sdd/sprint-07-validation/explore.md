# Exploration: Sprint 07 Validation

## Current State

### FSM Implementation (Good)
- `TicketStateMachine`: Valid transitions enforced via `canTransition()` and `transition()` methods
- States: new → active → waiting → resolved → closed
- InvalidTransitionException thrown for invalid transitions

### SLA Timestamps (Good)
- `first_response_at`: Set by HandleOutboundMessageUseCase (line 66-98)
- `resolved_at`: Set by TransitionTicketStatusUseCase
- `closed_at`: Set by AutoCloseTicketUseCase

### Idempotency (MISSING)
- Only `externalId` is checked for inbound messages (HandleInboundMessageUseCase line 68-80)
- HandleOutboundMessageUseCase sets `first_response_at` if empty - NO idempotency check
- Reprocessing same outbound message WILL change timestamp
- No idempotency key tracking for events

### Concurrency (NOT PROTECTED)
- No optimistic locking on tickets
- No version column to detect concurrent modifications
- Race condition: inbound+outbound simultaneous = unpredictable state

### AutoClose (PARTIAL)
- Checks resolved_at but NOT recent activity on conversation
- No check for messages AFTER resolved_at
- May close tickets that received new messages

## Affected Areas

| File | Issue |
|------|-------|
| HandleOutboundMessageUseCase.php | No idempotency - reprocesses change first_response_at |
| TransitionTicketStatusUseCase.php | No version check for concurrency |
| AutoCloseTicketUseCase.php | Doesn't check conversation.last_message_at |
| Ticket entity | No version column for optimistic locking |

## Approaches

### 1. Idempotency via Event Keys (Recommended)
- Add `event_key` column to tickets (unique constraint per event type)
- Track: `outbound_initial`, `resolve`, etc.
- On reprocess: if event key exists, skip state change

### 2. Optimistic Locking
- Add `version` column to Ticket entity
- Use Doctrine's `@Version` annotation
- Handle ConcurrentModificationException

### 3. AutoClose Activity Check
- Query messages after resolved_at before closing
- Only close if no new messages since resolved

## Recommendation

**Approach**: Implement all three (idempotency, optimistic locking, auto-close fix)

**Order**:
1. Add event_key tracking for idempotency
2. Add version column for concurrency
3. Fix AutoClose to check conversation activity

**Effort**: Medium

## Risks

- Breaking change: need migration for version column
- Event key naming must be consistent across use cases

## Ready for Proposal

Yes - Proceed to sdd-propose