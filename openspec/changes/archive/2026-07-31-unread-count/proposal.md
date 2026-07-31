# Proposal: Real Unread Message Count per Ticket

## Intent

The inbox ticket list displays a hardcoded `unread_count: 0`. Agents cannot see which tickets have new inbound messages. We need per-agent read tracking so each agent sees their own accurate count of unread messages per ticket.

## Scope

### In Scope
- New `agent_ticket_reads` table (agent_id, ticket_id, last_read_at)
- New endpoint `PUT /api/tickets/{id}/mark-read` to persist read timestamp
- Modify `ListTicketsQuery` to calculate real unread count via subquery
- Frontend: call `mark-read` API when opening a conversation, remove local-only `markAsRead()`

### Out of Scope
- Real-time unread count updates via Mercure (deferred)
- Per-message read receipts
- Bulk mark-as-read

## Capabilities

### New Capabilities
- `ticket-unread-count`: Per-agent tracking of unread inbound messages per ticket, calculated as messages created after the agent's last read timestamp.

### Modified Capabilities
None — existing spec behavior is unchanged.

## Approach

**Option A from exploration: `agent_ticket_reads` table.**

- **Table**: `agent_ticket_reads(agent_id, ticket_id, last_read_at)` — composite PK (agent_id, ticket_id), FK to agents and tickets.
- **Mark as read**: `PUT /api/tickets/{id}/mark-read` → upserts `last_read_at = NOW()`. Called by frontend when agent opens conversation.
- **Calculate unread**: Subquery in `ListTicketsQuery` counting inbound messages where `created_at > COALESCE(last_read_at, '1970-01-01')`. Left join ensures zero for never-opened tickets.
- **Frontend**: Replace `TicketStore.markAsRead()` with `this.http.put(\`/api/tickets/${id}/mark-read\`)`. Badge UI already renders from `metrics.unread_count` — no template changes needed.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/migrations/` | New | Migration for `agent_ticket_reads` table + index |
| `apps/api/src/Entity/` | New | `AgentTicketRead` Doctrine entity |
| `apps/api/src/Controller/InboxController.php` | Modified | New `markRead()` action |
| `apps/api/src/Application/Query/ListTicketsQuery.php` | Modified | Replace hardcoded `0` with subquery |
| `apps/web/src/app/features/tickets/ticket.store.ts` | Modified | `markAsRead()` → HTTP call |
| `apps/web/src/app/features/tickets/ticket.service.ts` | Modified | New `markAsRead(id)` method |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Subquery per ticket degrades list performance | Med | Index `messages(conversation_id, direction, created_at)`; test with realistic volume |
| Stale count between message arrival and list refresh | Low | Acceptable for MVP; denormalized count via async event handler deferred |

## Rollback Plan

1. Drop `agent_ticket_reads` table via down-migration
2. Revert `ListTicketsQuery` to hardcoded `0`
3. Revert frontend to local-only `markAsRead()`

No data loss risk — table is derived state only.

## Dependencies

- Existing `agents` and `tickets` tables (present since sprint 01)

## Success Criteria

- [ ] `GET /api/tickets` returns `unread_count > 0` for tickets with new inbound messages after last read
- [ ] `PUT /api/tickets/{id}/mark-read` resets unread count to 0 for the calling agent
- [ ] Different agents see independent unread counts for the same ticket
- [ ] Badge updates correctly in the inbox UI after mark-read + re-list
