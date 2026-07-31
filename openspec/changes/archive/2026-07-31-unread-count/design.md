# Design: Real Unread Message Count per Ticket

## Technical Approach

Persist per-agent read state in a new `agent_ticket_reads(agent_id, ticket_id, last_read_at)` table. Replace the hardcoded `unread_count: 0` in `ListTicketsQuery` with a correlated subquery that counts inbound messages newer than the agent's `last_read_at`. Add a `PUT /api/tickets/{id}/mark-read` endpoint that upserts the row. The Angular client fires that endpoint from `ConversationComponent` whenever `activeTicket` changes, removing the local-only `markAsRead` write from `TicketStore`.

This is Approach A from `exploration.md` and matches the proposal's "Mark as read" capability. It is per-agent safe, requires a single row write per open, and uses an index-friendly subquery.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|----------|--------|--------------|-----------|
| Read-state table | New `agent_ticket_reads` (composite PK) | Column on `tickets`; per-message `read_at` | Per-agent isolation; single upsert per open; no `O(n)` writes |
| Subquery shape | Correlated subquery in `ListTicketsQuery` SELECT | LEFT JOIN + GROUP BY | Mirrors existing `current_active_tickets` style; no extra GROUP BY; one place to read |
| Mark-read trigger | Dedicated `PUT /api/tickets/{id}/mark-read` | Side-effect on `GET /messages` | Idempotent, cacheable, single responsibility; client decides when "open" is |
| Agent resolution | Inject `Security` and call `User::getAgent()` (MeController pattern) | New `CurrentAgentProvider` service | Existing pattern is already used 8+ times in `MeController`; do not introduce a new abstraction for one call site |
| Frontend trigger | `effect()` in `ConversationComponent` watching `activeTicket` | Call from `selectTicket` in `TicketListComponent` | The conversation is "opened" only when it actually renders; centralizes the UX; survives deep-linking |
| Optimistic store update | Keep `markAsRead()` local zeroing alongside the API call | Drop the local update; rely on next list refresh | Badge feels instant; next list fetch overwrites correctly |

## Data Flow

```
Agent clicks ticket
       │
       ▼
TicketListComponent.selectTicket()
       │  sets activeTicket → loads messages
       ▼
ConversationComponent.ticketResetEffect()   ◀── effect on activeTicket
       │
       ├──► TicketStore.markTicketAsRead(id)   ── HTTP PUT /api/tickets/{id}/mark-read
       │                                            │
       │                                            ▼
       │                                     AgentTicketReadRepository.upsert()
       │                                            │
       │                                            ▼
       │                                     INSERT ... ON DUPLICATE KEY UPDATE
       │                                     last_read_at = NOW()
       │
       └──► optimistic local unread_count = 0
       
Next GET /api/tickets
       │
       ▼
ListTicketsQuery  ── LEFT JOIN agent_ticket_reads atr ON atr.ticket_id = t.id AND atr.agent_id = ?
                    ── subquery: COUNT inbound messages where created_at > COALESCE(atr.last_read_at, '1970-01-01')
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/migrations/Version2026MMDDHHMMSS.php` | Create | New `agent_ticket_reads` table + indexes; down() drops it |
| `apps/api/src/Domain/Ticket/AgentTicketRead.php` | Create | Doctrine entity, composite PK `(agent_id, ticket_id)`, FKs with `ON DELETE CASCADE` |
| `apps/api/src/Repository/AgentTicketReadRepository.php` | Create | `upsert(int $agentId, int $ticketId): void` using raw `INSERT ... ON DUPLICATE KEY UPDATE` |
| `apps/api/src/Controller/InboxController.php` | Modify | Inject `Security` + `AgentTicketReadRepository`; add `markRead(int $id)` action; map `PUT /tickets/{id}/mark-read` in `routes.yaml` |
| `apps/api/src/Application/Query/ListTicketsQuery.php` | Modify | Inject `AgentTicketReadRepository` (or pass agent id in); replace `'unread_count' => 0` with subquery; add `agentId` parameter |
| `apps/api/config/routes.yaml` | Modify | Add `app_inbox_ticket_mark_read` route |
| `apps/web/src/app/core/api/ticket-api.service.ts` | Modify | Add `markAsRead(id: string): Observable<void>` calling `PUT /api/tickets/{id}/mark-read` |
| `apps/web/src/app/core/stores/ticket.store.ts` | Modify | `markAsRead` now delegates to `TicketApiService.markAsRead`; keep optimistic `unread_count = 0` after success |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts` | Modify | New `effect()` reacting to `activeTicket` change → calls `ticketStore.markAsRead(id)` |

`TicketCardComponent`, the badge HTML, and `Ticket` interface require no changes — they already render `metrics.unread_count`.

## Interfaces / Contracts

**Endpoint**
```
PUT /api/tickets/{id}/mark-read       ROLE_AGENT
→ 204 No Content
→ 404 if ticket not found
→ 401 if unauthenticated
```

**SQL fragment added to `ListTicketsQuery` SELECT** (correlated; `?` is the calling agent's id; one extra placeholder in the existing `executeQuery` call):
```sql
(SELECT COUNT(*) FROM messages m
 JOIN agent_ticket_reads atr
   ON atr.ticket_id = t.id AND atr.agent_id = ?
 WHERE m.conversation_id = conv.id
   AND m.direction = 'inbound'
   AND m.created_at > COALESCE(atr.last_read_at, '1970-01-01')
) AS unread_count
```

**Migration** (new table):
```sql
CREATE TABLE agent_ticket_reads (
  agent_id     BIGINT NOT NULL,
  ticket_id    BIGINT NOT NULL,
  last_read_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (agent_id, ticket_id),
  INDEX idx_atr_ticket (ticket_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`;
ALTER TABLE agent_ticket_reads ADD CONSTRAINT FK_ATR_AGENT  FOREIGN KEY (agent_id)  REFERENCES agents(id)  ON DELETE CASCADE;
ALTER TABLE agent_ticket_reads ADD CONSTRAINT FK_ATR_TICKET FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE;
```

**Entity shape** (skeleton): `agentId: int` (PK), `ticketId: int` (PK), `lastReadAt: \DateTimeImmutable` — pure read model, no domain methods needed.

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | `AgentTicketReadRepository::upsert` insert + update branches | In-memory SQLite; assert row exists after first call, `last_read_at` advances after second |
| Integration | `InboxController::markRead` — 204 on success, 404 on missing, 401 unauth | WebTestCase; mock JWT user; seed ticket; assert DB row |
| Integration | `ListTicketsQuery` returns real counts | Seed: 3 inbound messages, no read row → count=3; call mark-read, re-list → count=0; add new inbound → count=1 |
| E2E | Angular conversation open triggers mark-read | Karma spec on `TicketStore` + `ConversationComponent`; assert HTTP call with right id; assert optimistic local update |

## Migration / Rollout

Single forward migration creates the table. Empty initial state means **every existing ticket counts as fully unread** for every agent on first deploy — acceptable for an MVP where unread was previously 0. Rollback: run the migration's `down()` (drops table), revert the controller/query changes, revert the frontend `markAsRead` to the old local-only mutation. No data loss — table is derived state.

**Indexes**: existing `idx_messages_conversation_id` + `idx_messages_conversation_created_id` already cover the subquery filter; no new message index needed. `agent_ticket_reads` PK `(agent_id, ticket_id)` makes the subquery's `JOIN` O(1) per ticket.

## Open Questions

- **Effect re-fire**: the existing `ticketResetEffect` already runs on `activeTicket` change. We will add a second effect for mark-read to keep concerns separate. If we wanted to merge them, we would lose the side-effect-isolation. Decision: keep separate.
- **Bulk mark-read**: out of scope per proposal. Same endpoint shape (`PUT /api/tickets/{id}/mark-read`) is easy to extend to bulk in a future change.
