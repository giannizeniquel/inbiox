# Ticket Unread Count Specification

## Purpose

Per-agent tracking of unread inbound messages per ticket. Each agent sees an accurate count of messages they have not yet viewed, calculated from a persisted `last_read_at` timestamp.

## Requirements

### REQ-1: Mark Conversation as Read

The system MUST provide a `PUT /api/tickets/{id}/mark-read` endpoint that records or updates a `last_read_at` timestamp for the calling agent and the given ticket. The timestamp MUST be set to the current server time. If a record already exists for the (agent, ticket) pair, it MUST be updated (upsert).

#### Scenario: Agent opens conversation with unread messages

- GIVEN an agent has a ticket with 3 unread inbound messages
- WHEN the agent opens the conversation (frontend calls `PUT /api/tickets/{id}/mark-read`)
- THEN the system records `last_read_at = NOW()` for that (agent, ticket) pair
- AND the ticket's unread count becomes 0 on next list refresh

#### Scenario: Agent switches to a different ticket

- GIVEN agent is viewing ticket A (already marked read) and switches to ticket B
- WHEN the frontend calls `PUT /api/tickets/B/mark-read`
- THEN ticket B's `last_read_at` is updated to NOW()
- AND ticket B's unread count becomes 0 on next list refresh

#### Scenario: Mark-read for non-existent ticket

- GIVEN a ticket ID that does not exist in the system
- WHEN the endpoint `PUT /api/tickets/{id}/mark-read` is called
- THEN the system returns HTTP 404

#### Scenario: Unauthenticated mark-read

- GIVEN no authentication token is provided
- WHEN the endpoint `PUT /api/tickets/{id}/mark-read` is called
- THEN the system returns HTTP 401

### REQ-2: Inbox List Shows Unread Count

The system MUST return a real `unread_count` in the ticket list response (`GET /api/tickets`). The count MUST equal the number of inbound messages created after the agent's `last_read_at` for that ticket. Only inbound messages SHALL be counted.

#### Scenario: Agent receives new message while NOT viewing the conversation

- GIVEN agent has `last_read_at` set for a ticket and is NOT viewing it
- WHEN a new inbound message arrives for that ticket
- THEN the next `GET /api/tickets` response shows `unread_count` incremented by 1

### REQ-3: First-Time View Counts All Messages as Unread

When no `last_read_at` record exists for an (agent, ticket) pair, the system MUST count ALL existing inbound messages as unread.

#### Scenario: New agent assigned to existing ticket with messages

- GIVEN a ticket has 5 inbound messages and no `agent_ticket_reads` row for the agent
- WHEN the agent views the ticket list (`GET /api/tickets`)
- THEN the ticket's `unread_count` is 5

### REQ-4: Unread Count Is Zero After Full View

After the agent has viewed all messages (i.e., `last_read_at` is greater than or equal to the `created_at` of the latest inbound message), the `unread_count` MUST be 0.

#### Scenario: Agent receives new message while actively viewing conversation

- GIVEN agent is viewing a conversation and `last_read_at` is current
- WHEN a new inbound message arrives while the agent is still viewing
- THEN the badge stays at 0 (the mark-read was already called; count was zero and the new message has not yet been "read" but the list has not been refreshed — the count reflects the last list fetch)

## Constraints

- Read state is per-agent: different agents MUST see independent unread counts for the same ticket.
- Only inbound messages count toward unread (outbound messages from the agent are excluded).
- The mark-read endpoint MUST require authentication (agent identity).
