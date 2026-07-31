# Tasks: Real Unread Message Count per Ticket

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~250–320 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

## Phase 1: Database & Entity Foundation

- [ ] 1.1 Create `apps/api/migrations/Version20260731000001.php` — `agent_ticket_reads` table with composite PK `(agent_id, ticket_id)`, FKs to `agents`/`tickets` ON DELETE CASCADE, index `idx_atr_ticket`. Down drops table.
- [ ] 1.2 Create `apps/api/src/Domain/Ticket/AgentTicketRead.php` — Doctrine entity, composite PK, properties `agentId`, `ticketId`, `lastReadAt: \DateTimeImmutable`.
- [ ] 1.3 Create `apps/api/src/Repository/AgentTicketReadRepository.php` — `upsert(int $agentId, int $ticketId): void` using raw `INSERT ... ON DUPLICATE KEY UPDATE last_read_at = NOW()`.

## Phase 2: Backend API

- [ ] 2.1 Add `markRead(int $id)` action to `apps/api/src/Controller/InboxController.php` — inject `Security` + `AgentTicketReadRepository`; resolve agent via `User::getAgent()`; call `upsert()`; return 204. Throw 404 if ticket not found.
- [ ] 2.2 Add route `app_inbox_ticket_mark_read` (`PUT /api/tickets/{id}/mark-read`) in `apps/api/config/routes.yaml`.
- [ ] 2.3 Modify `apps/api/src/Application/Query/ListTicketsQuery.php` — accept `agentId` parameter; replace `'unread_count' => 0` with correlated subquery counting inbound messages where `created_at > COALESCE(atr.last_read_at, '1970-01-01')`.

## Phase 3: Frontend Integration

- [ ] 3.1 Add `markAsRead(id: string): Observable<void>` to `apps/web/src/app/core/api/ticket-api.service.ts` — `PUT /api/tickets/${id}/mark-read`.
- [ ] 3.2 Update `markAsRead()` in `apps/web/src/app/core/stores/ticket.store.ts` — delegate to `TicketApiService.markAsRead()`; keep optimistic `unread_count = 0` on success.
- [ ] 3.3 Add `effect()` in `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts` watching `activeTicket` → call `ticketStore.markAsRead(id)` on change.

## Phase 4: Testing

- [ ] 4.1 Unit test `AgentTicketReadRepository::upsert` — assert insert creates row, second call updates `last_read_at`.
- [ ] 4.2 Integration test `InboxController::markRead` — 204 on success (assert DB row), 404 on missing ticket, 401 unauthenticated.
- [ ] 4.3 Integration test `ListTicketsQuery` — seed 3 inbound messages + no read row → count=3; call mark-read → count=0; add new inbound → count=1.
- [ ] 4.4 Frontend test `TicketStore.markAsRead` — assert HTTP PUT called with correct id; assert optimistic local `unread_count` zeroed.
