# Proposal: Fix Workspace Segregation in Inbound Messages

## Intent

When a contact messages a new workspace, the system reuses their existing conversation and ticket from another workspace — because `phone_number_id` is ignored during repository lookups. This is a data isolation bug: workspace-level segregation is part of the domain model (both entities have the column + indexes) but broken in the write path.

## Scope

### In Scope
- Add `?string $phoneNumberId = null` parameter to 3 repository interface methods
- Update 3 repository implementation methods to filter by `phoneNumberId` when provided
- Update 2 call sites in `HandleInboundMessageUseCase` to pass the already-available `$phoneNumberId`

### Out of Scope
- Frontend changes (none needed — read path already filters correctly)
- `HandleStatusUpdateUseCase` / `HandleOutboundMessageUseCase` (not affected)
- Database migrations (column and indexes already exist from `Version20260613000002`)

## Capabilities

### New Capabilities
None — this is a bug fix, not a feature.

### Modified Capabilities
None — workspace segregation was always the intended behavior. The `phone_number_id` column, indexes, and write-path assignment at lines 162-164 and 205-207 already exist. Only the read-path in repository lookups was broken.

## Approach

Surgical: add optional `?string $phoneNumberId` parameter to 3 interface methods (`ConversationRepositoryInterface::findOpenByContact`, `TicketRepositoryInterface::findOpenByContact`, `TicketRepositoryInterface::findNotClosedByContact`). Default `null` preserves backward compatibility. Implementations add the filter via `findOneBy()` criteria array (Conversation) or QueryBuilder (Ticket — required for `status IN (...)` arrays). Update 2 call sites in `HandleInboundMessageUseCase::executeInternal()` lines 156 and 196 to pass `$phoneNumberId`. Follows the existing pattern in `resolveActiveTicketIds` (ListContactsQuery lines 118-154).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `Domain/Conversation/ConversationRepositoryInterface.php` | Modified | Add `?string $phoneNumberId` param to `findOpenByContact` |
| `Domain/Ticket/TicketRepositoryInterface.php` | Modified | Add `?string $phoneNumberId` param to 2 methods |
| `Repository/ConversationRepository.php` | Modified | Filter by `phoneNumberId` in `findOpenByContact` |
| `Repository/TicketRepository.php` | Modified | Filter by `phoneNumberId` in `findOpenByContact` + `findNotClosedByContact` |
| `Application/UseCase/HandleInboundMessageUseCase.php` | Modified | Pass `$phoneNumberId` at lines 156, 196 |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Existing call sites break | Low | Default `null` = no filter, backward-compatible. Only caller is `HandleInboundMessageUseCase`, which is being fixed. |
| `phoneNumberId` is `null` in webhook payload | Low | Filter only applied when non-null. Existing behavior preserved for legacy callers. |
| Ticket QueryBuilder switch introduces ORM diff | Low | Already used in `countActiveForAgent` — proven pattern. |

## Rollback Plan

`git revert` the commit. Pure backend — no DB changes, no frontend. 5 files.

## Dependencies

None. Entities, columns, and indexes already exist. Only the repository lookup logic needs fixing.

## Success Criteria

- [ ] Contact messaging new workspace → new conversation + ticket created with correct `phone_number_id`
- [ ] Contact messaging same workspace → existing conversation + ticket reused
- [ ] Contact messaging without `phoneNumberId` (legacy) → existing behavior preserved
- [ ] Existing tests pass without modification
- [ ] `php bin/phpunit` green
