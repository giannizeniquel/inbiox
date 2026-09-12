# Realtime Event Taxonomy

This document defines all event types used in Inbiox's realtime communication layer (Mercure/SSE).

## Ticket Events

| Event | Topic | Description | Payload |
|-------|-------|-------------|---------|
| `ticket.created` | `ticket/{id}` | Fired when a new ticket is created | `{ ticket: Ticket }` |
| `ticket.updated` | `ticket/{id}` | Fired when ticket fields change | `{ ticket: Ticket }` |
| `ticket.assigned` | `ticket/{id}/assigned` | Fired when ownership changes (assign, reassign, unassign) | `{ ticket_id, agent_id, assignment_type, timestamp }` |
| `ticket.status_changed` | `ticket/{id}/status` | Fired when ticket FSM status changes | `{ ticket_id, previous_status, new_status, timestamp }` |

## Message Events

| Event | Topic | Description | Payload |
|-------|-------|-------------|---------|
| `message.received` | `ticket/{ticketId}` | Inbound message from customer | `{ message: Message, ticketId: string }` |
| `message.sent` | `ticket/{ticketId}` | Outbound message from agent | `{ message: Message, ticketId: string }` |
| `message.failed` | `ticket/{ticketId}` | Message delivery failed | `{ ticketId: string, reason: string }` |

## Contact Events

| Event | Topic | Description | Payload |
|-------|-------|-------------|---------|
| `contact.created` | `contacts` | New contact added | `{ contact: Contact }` |
| `contact.updated` | `contacts/{id}` | Contact details changed | `{ contactId: string, changes: Partial<Contact> }` |

## Agent Events

| Event | Topic | Description | Payload |
|-------|-------|-------------|---------|
| `agent.availability_changed` | `agent/{id}/availability` + `agents` (broadcast) | Agent availability override set or cleared | `{ agent_id, is_available, source, override_until, changed_at }` |
| `agent.shift_changed` | `agent/{id}/shifts` + `agents` (broadcast) | Agent shift created or deleted | `{ agent_id, shift_id, action, day_of_week, start_time, end_time, timestamp }` |

Broadcast topic `agents` is reserved for Sprint 10.5 capacity bar.

### Agent Availability Changed Payload

Emitted when:
- `POST /agents/{id}/availability` — override set or updated
- `DELETE /agents/{id}/availability` — override cleared
- Override TTL expiry evaluated at assignment time (no explicit event — expiry is read-time)

```json
{
  "agent_id": 5,
  "is_available": true,
  "source": "override",
  "override_until": "2026-06-14T17:30:00Z",
  "changed_at": "2026-06-14T15:30:00Z"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `agent_id` | int | Agent whose availability changed |
| `is_available` | bool | Current computed availability |
| `source` | string | `"override"` — always `override` for this event (schedule/capacity changes are not event-driven) |
| `override_until` | string? | ISO 8601 UTC expiry timestamp; `null` when cleared or persistent |
| `changed_at` | string | ISO 8601 UTC timestamp of the change |

### Agent Shift Changed Payload

Emitted when:
- `POST /agents/{id}/shifts` — shift created
- `DELETE /agents/{id}/shifts/{shiftId}` — shift deleted

```json
{
  "agent_id": 5,
  "shift_id": 10,
  "action": "created",
  "day_of_week": 1,
  "start_time": "08:00",
  "end_time": "16:00",
  "timestamp": "2026-06-14T15:30:00Z"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `agent_id` | int | Agent whose shift changed |
| `shift_id` | int | ID of the shift created or deleted |
| `action` | string | `"created"` or `"deleted"` |
| `day_of_week` | int | 1=Monday ... 7=Sunday (ISO-8601) |
| `start_time` | string | Local time (HH:MM) in agent's timezone |
| `end_time` | string | Local time (HH:MM) in agent's timezone |
| `timestamp` | string | ISO 8601 UTC timestamp of the change |

## Assignment / Ownership Events

These events replace the legacy `agent.assigned` and `agent.unassigned` topics.
All ownership changes now use the `ticket.assigned` event with the `assignment_type` field for disambiguation.

| Event | Topic | Trigger | Payload |
|-------|-------|---------|---------|
| `ticket.assigned` | `ticket/{id}/assigned` | Any ownership change | `{ ticket_id, agent_id, assignment_type, timestamp }` |

### Ticket Assigned Payload

```json
{
  "ticket_id": 100,
  "agent_id": 5,
  "assignment_type": "AUTO_ASSIGN",
  "timestamp": "2026-06-14T15:30:00Z"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `ticket_id` | int | The ticket whose ownership changed |
| `agent_id` | int? | New owner ID, or `null` for UNASSIGN |
| `assignment_type` | string | One of: `AUTO_ASSIGN`, `MANUAL_ASSIGN`, `REASSIGN`, `UNASSIGN` |
| `timestamp` | string | ISO 8601 UTC timestamp |

**`assignment_type` values**:

| Type | Meaning |
|------|---------|
| `AUTO_ASSIGN` | System assigned. Two independent sources emit this type: (1) `AutoAssignTicketUseCase` — least-loaded-agent auto-assignment of an unassigned ticket; (2) `HandleOutboundMessageUseCase` — reply-triggered claim, when any agent replies to a ticket that has no owner yet |
| `MANUAL_ASSIGN` | Supervisor assigned via `AssignTicketUseCase` |
| `REASSIGN` | Transfer from one agent to another. Two independent sources emit this type: (1) `AssignTicketUseCase` — a supervisor manually reassigns; (2) `HandleOutboundMessageUseCase` — reply-triggered reassignment, when any agent (any role — `ROLE_AGENT`/`ROLE_SUPERVISOR`/`ROLE_ADMIN`) other than the current owner replies to an already-owned, non-resolved/closed ticket. This is intentional, first-class behavior, not an undocumented side effect (see `openspec/specs/reply-auto-assignment/spec.md`) |
| `UNASSIGN` | Ownership removed (e.g. ticket resolved/closed) |

**Reply-triggered ownership change (`HandleOutboundMessageUseCase`)**: any outbound agent reply claims an unassigned ticket or reassigns an already-owned ticket to the replying agent, regardless of role — no "Reassign tickets" permission check applies, since this is system-triggered, not a user-initiated reassignment request. On a `resolved`/`closed` ticket, ownership is instead frozen: a privileged sender (`ROLE_SUPERVISOR`/`ROLE_ADMIN`) or the current owner may still reply without any ownership change, but a non-owning, non-privileged agent's reply is rejected outright with HTTP 409 `{"type": "TICKET_NOT_OWNED"}` — no message is persisted and no ownership change occurs.

## Event Source Mapping

| Domain Event | Realtime Event | Topic |
|-------------|----------------|-------|
| `TicketCreated` | `ticket.created` | `ticket/{id}` |
| `TicketUpdatedEvent` | `ticket.updated` | `ticket/{id}` |
| `InboxUpdatedEvent` | `ticket.updated` | `ticket/{id}` |
| `TicketStatusChangedEvent` | `ticket.status_changed` | `ticket/{id}/status` |
| `TicketOwnershipChangedEvent` | `ticket.assigned` | `ticket/{id}/assigned` |
| `AgentAvailabilityChangedEvent` | `agent.availability_changed` | `agent/{id}/availability` + `agents` |
| `AgentShiftChangedEvent` | `agent.shift_changed` | `agent/{id}/shifts` + `agents` |

## Payload Types

### Ticket
```typescript
interface Ticket {
  id: string;
  externalId?: string;
  contactName: string;
  unreadCount: number;
  lastMessage: string;
  lastMessageAt: string;
  isAutomated: boolean;
  slaStatus: 'ok' | 'warn' | 'breach';
  fsmStatus: 'new' | 'active' | 'waiting' | 'resolved' | 'closed';
}
```

### Message
```typescript
interface Message {
  id: string;
  tempId?: string;
  ticketId: string;
  sender: 'agent' | 'customer' | 'system';
  content: string;
  createdAt: string;
  deliveryStatus: 'sending' | 'sent' | 'read' | 'failed';
  failedReason?: string;
  externalId?: string;
}
```

### Contact
```typescript
interface Contact {
  id: string;
  name: string;
  phone?: string;
  email?: string;
  avatar?: string;
  metadata?: Record<string, any>;
}
```

## Seq Ordering

All events include a `seq` field for ordering. Clients MUST validate sequence numbers:

```typescript
if (incomingSeq > currentSeq) {
  // Apply event
} else if (incomingSeq === currentSeq) {
  // Duplicate - ignore
} else {
  // Old event - ignore (potential race condition)
}
```

## Idempotency

Use `eventId` (UUID) to detect duplicate events. Track processed IDs in a set with size limit (1000 entries max).