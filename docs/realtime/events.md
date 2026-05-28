# Realtime Event Taxonomy

This document defines all event types used in Inbiox's realtime communication layer (Mercure/SSE).

## Ticket Events

| Event | Description | Payload |
|-------|-------------|---------|
| `ticket.created` | Fired when a new ticket is created | `{ ticket: Ticket }` |
| `ticket.updated` | Fired when ticket fields change | `{ ticket: Ticket }` |
| `ticket.assigned` | Fired when an agent is assigned | `{ ticket: Ticket, assigneeId: number }` |

## Message Events

| Event | Description | Payload |
|-------|-------------|---------|
| `message.received` | Inbound message from customer | `{ message: Message, ticketId: string }` |
| `message.sent` | Outbound message from agent | `{ message: Message, ticketId: string }` |
| `message.failed` | Message delivery failed | `{ ticketId: string, reason: string }` |

## Contact Events

| Event | Description | Payload |
|-------|-------------|---------|
| `contact.created` | New contact added | `{ contact: Contact }` |
| `contact.updated` | Contact details changed | `{ contactId: string, changes: Partial<Contact> }` |

## Agent Events

| Event | Description | Payload |
|-------|-------------|---------|
| `agent.assigned` | Agent assigned to ticket | `{ ticketId: string, agentId: number }` |
| `agent.unassigned` | Agent unassigned from ticket | `{ ticketId: string, agentId: number }` |

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