# Design: Fix Workspace Segregation in Inbound Messages

## Technical Approach

Add an optional `?string $phoneNumberId = null` parameter to three repository lookup methods (`ConversationRepository::findOpenByContact`, `TicketRepository::findOpenByContact`, `TicketRepository::findNotClosedByContact`) and their interfaces. When the parameter is non-null, the Doctrine query adds an `AND phone_number_id = :phoneNumberId` clause. When null, the filter is omitted (backward-compatible). Update the two production call sites in `HandleInboundMessageUseCase::executeInternal()` to pass the already-available `$phoneNumberId` parameter. This mirrors the optional-filter pattern already established in `ListContactsQuery::resolveActiveTicketIds()` (lines 118–154).

Pure backend surgery: no DB migration, no frontend, no new entities. Five files modified.

## Architecture Decisions

| # | Decision | Choice | Alternatives | Rationale |
|---|----------|--------|--------------|-----------|
| 1 | Parameter placement | `?string $phoneNumberId = null` as last param, after `$contact` | New method (e.g. `findOpenByContactInWorkspace`); value object wrapper | Matches existing `resolveActiveTicketIds` signature; default `null` = backward compat for any legacy caller; avoids interface bloat |
| 2 | Filter condition | `phone_number_id = :phoneNumberId` only when `!== null` | Always filter (require `phoneNumberId` on interface); filter on `IS NULL OR =` when null | Probes show only `HandleInboundMessageUseCase` calls these; other code paths in `HandleStatusUpdateUseCase` / `HandleOutboundMessageUseCase` don't need contact-based lookup. Keeping the optional filter avoids breaking any future caller that lacks workspace context |
| 3 | `ConversationRepository` impl | `findOneBy()` criteria array — add `'phoneNumberId' => $phoneNumberId` conditionally | QueryBuilder | Status is a single scalar (`'open'`); no `IN (...)`; criteria array is the existing pattern in this file and Doctrine's property-name resolution handles the column mapping |
| 4 | `TicketRepository` impl | Switch to `createQueryBuilder()` for both methods | Keep `findOneBy()` with `IN` array | `findOneBy()` already supports `status IN (...)` via array, but mixing optional `andWhere` with `findOneBy` array is awkward; QueryBuilder is the proven pattern (`countActiveForAgent`, line 46) and lets us cleanly add `andWhere('t.phoneNumberId = :phoneNumberId')` |
| 5 | Doctrine property name | Use `phoneNumberId` (camelCase) in DQL | Use raw `phone_number_id` column | Doctrine resolves camelCase property → snake_case column via the entity's `#[ORM\Column(name: 'phone_number_id')]` mapping; matches the entity definition and is type-safe |
| 6 | Caller audit | Update only the two existing call sites in `HandleInboundMessageUseCase` | Search-and-replace across whole repo | Grep proves `findOpenByContact` (Conversation) has 1 caller, `findNotClosedByContact` (Ticket) has 1 caller, `findOpenByContact` (Ticket) has 0 callers. All in the same use case. No other code paths to update |
| 7 | Entity status types | Reuse `TicketStatus::NEW/ACTIVE/WAITING` enum constants in the QB filter | Hardcode strings | `TicketStatus` enum already exists and is used in `countActiveForAgent`; prevents string drift |

## Data Flow

    WebhookController
        │  payload { phone, message, phoneNumberId, ... }
        ▼
    HandleInboundMessageUseCase::processInboundMessage(phone, message, externalId, phoneNumberId, contactName)
        │
        ▼
    executeInternal(phone, message, externalId, phoneNumberId, contactName)
        │
        ├─→ conversationRepo->findOpenByContact($contact, $phoneNumberId)   ← line 156
        │       │
        │       └─ if $phoneNumberId !== null → AND phone_number_id = :phoneNumberId
        │
        ├─→ ticketRepo->findNotClosedByContact($contact, $phoneNumberId)   ← line 196
        │       │
        │       └─ if $phoneNumberId !== null → andWhere(t.phoneNumberId = :phoneNumberId)
        │
        ├─→ (no match) → persist new Conversation + Ticket with setPhoneNumberId($phoneNumberId)
        │
        └─→ (match) → reuse existing entity; message appended; ticket lock acquired

`$phoneNumberId` is already in scope at both lookup points (line 139 signature) — the bug is purely that it was never threaded into the repository calls.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/src/Domain/Conversation/ConversationRepositoryInterface.php` | Modify | Add `?string $phoneNumberId = null` to `findOpenByContact` |
| `apps/api/src/Domain/Ticket/TicketRepositoryInterface.php` | Modify | Add `?string $phoneNumberId = null` to `findOpenByContact` and `findNotClosedByContact` |
| `apps/api/src/Repository/ConversationRepository.php` | Modify | Conditionally add `phoneNumberId` to criteria array |
| `apps/api/src/Repository/TicketRepository.php` | Modify | Switch both methods to `createQueryBuilder()`; conditionally add `andWhere(t.phoneNumberId = :phoneNumberId)` |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Modify | Pass `$phoneNumberId` at lines 156 and 196 |

No new files. No deletes. No migrations.

## Interfaces / Contracts

```php
// ConversationRepositoryInterface.php
public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Conversation;

// TicketRepositoryInterface.php
public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket;
public function findNotClosedByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket;
```

### Implementation: `ConversationRepository::findOpenByContact`

```php
public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Conversation
{
    $criteria = ['contact' => $contact, 'status' => ConversationStatus::OPEN->value];
    if ($phoneNumberId !== null) {
        $criteria['phoneNumberId'] = $phoneNumberId;
    }
    return $this->findOneBy($criteria);
}
```

### Implementation: `TicketRepository::findOpenByContact` and `findNotClosedByContact`

```php
public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket
{
    $qb = $this->createQueryBuilder('t')
        ->where('t.contact = :contact')
        ->andWhere('t.status IN (:statuses)')
        ->setParameter('contact', $contact)
        ->setParameter('statuses', [TicketStatus::NEW->value, TicketStatus::ACTIVE->value, TicketStatus::WAITING->value]);

    if ($phoneNumberId !== null) {
        $qb->andWhere('t.phoneNumberId = :phoneNumberId')
           ->setParameter('phoneNumberId', $phoneNumberId);
    }

    return $qb->getQuery()->getOneOrNullResult();
}

public function findNotClosedByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket
{
    $qb = $this->createQueryBuilder('t')
        ->where('t.contact = :contact')
        ->andWhere('t.status IN (:statuses)')
        ->setParameter('contact', $contact)
        ->setParameter('statuses', [
            TicketStatus::NEW->value,
            TicketStatus::ACTIVE->value,
            TicketStatus::WAITING->value,
            TicketStatus::RESOLVED->value,
        ]);

    if ($phoneNumberId !== null) {
        $qb->andWhere('t.phoneNumberId = :phoneNumberId')
           ->setParameter('phoneNumberId', $phoneNumberId);
    }

    return $qb->getQuery()->getOneOrNullResult();
}
```

### Call site updates in `HandleInboundMessageUseCase`

```php
// Line 156
$conversation = $this->conversationRepo->findOpenByContact($contact, $phoneNumberId);

// Line 196
$ticket = $this->ticketRepo->findNotClosedByContact($contact, $phoneNumberId);
```

## Caller Audit

| Method | Production caller | Update needed? |
|--------|-------------------|----------------|
| `ConversationRepository::findOpenByContact` | `HandleInboundMessageUseCase::executeInternal` line 156 | Yes — pass `$phoneNumberId` |
| `TicketRepository::findOpenByContact` | None (zero call sites) | No — but interface updated for consistency |
| `TicketRepository::findNotClosedByContact` | `HandleInboundMessageUseCase::executeInternal` line 196 | Yes — pass `$phoneNumberId` |

All other matches in the grep were in `openspec/changes/**` docs and the repository definitions themselves. No test file currently mocks these with positional args beyond the mock object's default, so existing tests keep working.

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | `HandleInboundMessageUseCaseTest` — add 3 cases: same workspace reuses entity, different workspace creates new entity, null `phoneNumberId` falls back to legacy behavior | Mock `$this->conversationRepo` and `$this->ticketRepo` with `->expects($this->once())->method('findOpenByContact')->with($contact, 'pw1-456')->willReturn(null)`; assert new `Conversation` and `Ticket` are created via `em->expects($this->once())->method('persist')` |
| Unit | Repository methods (optional, integration covers it) | New `ConversationRepositoryTest` + `TicketRepositoryTest` against a real DB (SQLite or test MySQL): persist two conversations in workspaces A and B, assert `findOpenByContact($contact, 'A')` returns only A's |
| Integration | End-to-end: webhook payload with `phoneNumberId` lands in correct conversation/ticket | Reuse `Sprint13InboundTest` or create `FixWorkspaceSegregationTest`: two webhooks to same phone with different `phoneNumberId`, assert two distinct conversation + ticket pairs each with the correct `phone_number_id` |
| Regression | All existing tests pass unchanged | `docker exec chatapp_php vendor/bin/phpunit` |

`HandleInboundMessageUseCaseTest` already mocks all repos via `createMock`; new assertions slot in without restructuring.

## Migration / Rollout

No migration required. Column `phone_number_id` and indexes `idx_tickets_phone_number_id` / `idx_conversations_phone_number_id` already exist (migration `Version20260613000002`).

**Rollout**: single PR, 5 files. `git revert` reverts cleanly.

**Backwards compatibility**: any caller that does not pass `phoneNumberId` (default `null`) gets the pre-fix behavior — no filter applied. Production audit shows zero such callers today; this is a safety net for hypothetical future code.

**Performance**: indexed lookups; no N+1 introduced. Both indexes are already in place.

## Open Questions

None. All six design decisions are constrained by the existing codebase patterns (`resolveActiveTicketIds` for the conditional filter, `countActiveForAgent` for the QueryBuilder style, `TicketStatus` enum for status lists). The caller audit is exhaustive and the spec scenarios are mechanically implementable.
