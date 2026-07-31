# Exploration: Fix Workspace Segregation Bug in Inbound Message Handling

**Change**: `fix-workspace-segregation`
**Date**: 2026-07-29
**Phase**: sdd-explore
**Status**: Complete

---

## Bug Summary

When a contact messages a new workspace (e.g., "PW1"), the system incorrectly shows all messages/conversations from another workspace ("Inbiox") where the same contact had previous conversations. Additionally, no new ticket is created for PW1 because the system finds an existing open ticket from the "Inbiox" workspace.

---

## Current State

### 1. Domain Entities — `phone_number_id` Already Present

Both entities already have the `phone_number_id` column with getter/setter:

**`Conversation.php`** (line 40-41, 109-118):
```php
#[ORM\Column(name: 'phone_number_id', type: 'string', length: 36, nullable: true)]
private ?string $phoneNumberId = null;

public function getPhoneNumberId(): ?string { ... }
public function setPhoneNumberId(?string $phoneNumberId): self { ... }
```

**`Ticket.php`** (line 61-62, 207-216):
```php
#[ORM\Column(name: 'phone_number_id', type: 'string', length: 36, nullable: true)]
private ?string $phoneNumberId = null;

public function getPhoneNumberId(): ?string { ... }
public function setPhoneNumberId(?string $phoneNumberId): self { ... }
```

### 2. Database Indexes — Already Exist

Migration `Version20260613000002.php` (line 21, 25) created the indexes:
- `idx_tickets_phone_number_id` on `tickets(phone_number_id)`
- `idx_conversations_phone_number_id` on `conversations(phone_number_id)`

### 3. Repositories — Missing `phone_number_id` Filter

**`ConversationRepository.php`** (line 18-21):
```php
public function findOpenByContact(Contact $contact): ?Conversation
{
    return $this->findOneBy(['contact' => $contact, 'status' => 'open']);
}
```
No `phone_number_id` filter. Interface at `ConversationRepositoryInterface.php` (line 9) also lacks the parameter.

**`TicketRepository.php`** (lines 20-28):
```php
public function findOpenByContact(Contact $contact): ?Ticket
{
    return $this->findOneBy(['contact' => $contact, 'status' => ['new', 'active', 'waiting']]);
}

public function findNotClosedByContact(Contact $contact): ?Ticket
{
    return $this->findOneBy(['contact' => $contact, 'status' => ['new', 'active', 'waiting', 'resolved']]);
}
```
No `phone_number_id` filter. Interface at `TicketRepositoryInterface.php` (lines 10-11) also lacks the parameter.

### 4. `HandleInboundMessageUseCase` — Calls Repos Without `phoneNumberId`

**`HandleInboundMessageUseCase.php`** (line 156):
```php
// Line 156 — Missing phoneNumberId filter
$conversation = $this->conversationRepo->findOpenByContact($contact);
```

**`HandleInboundMessageUseCase.php`** (line 196):
```php
// Line 196 — Missing phoneNumberId filter
$ticket = $this->ticketRepo->findNotClosedByContact($contact);
```

The `$phoneNumberId` parameter IS available in the method (line 139: `executeInternal(..., ?string $phoneNumberId = null, ...)`) and IS correctly passed to the `Conversation` and `Ticket` constructors when creating new entities (lines 162-164, 205-207). It is NOT used in the lookup queries.

### 5. The Correct Pattern — `resolveActiveTicketIds`

**`ListContactsQuery.php`** (lines 118-154) shows the correct approach:

```php
private function resolveActiveTicketIds(array $items, array $contactIds, ?string $phoneNumberId): void
{
    // ...
    $phoneFilter = '';
    if ($phoneNumberId !== null) {
        $phoneFilter = 'AND t.phone_number_id = ?';
        $params[] = $phoneNumberId;
        $types[] = \Doctrine\DBAL\Types\Types::STRING;
    }

    $sql = "SELECT t.contact_id, MAX(t.id) as ticket_id
            FROM tickets t
            WHERE t.contact_id IN ($placeholders)
              AND t.status NOT IN ('closed', 'resolved')
              $phoneFilter
            GROUP BY t.contact_id";
    // ...
}
```

### 6. Other Use Cases — Not Affected

- **`HandleStatusUpdateUseCase`**: Finds messages by `externalId` (WAMID), then follows `conversation → ticket` chain. No contact-based lookup. No `phoneNumberId` filtering needed at lookup time.
- **`HandleOutboundMessageUseCase`**: Takes `ticketId` directly, uses `findById`. No contact-based lookup. Not affected.

---

## Detailed Bug Flow

**Scenario**: Contact `+54XXX` has an open ticket in workspace "Inbiox" (`phone_number_id = 'inbiox-123'`). They start a new conversation in workspace "PW1" (`phone_number_id = 'pw1-456'`).

**Step-by-step what happens**:

1. Webhook arrives with `phoneNumberId = 'pw1-456'` → `HandleInboundMessageUseCase::processInboundMessage()`
2. Contact found by phone → existing contact entity
3. **Line 156**: `findOpenByContact($contact)` returns the OPEN conversation from "Inbiox" workspace (wrong workspace!)
4. `$isNewConversation = false` — reuses existing conversation
5. Message attached to wrong conversation (which belongs to "Inbiox")
6. **Line 196**: `findNotClosedByContact($contact)` returns the open ticket from "Inbiox" (wrong workspace!)
7. `$isNewTicket = false` — reuses existing ticket from "Inbiox"
8. No new ticket created for "PW1"
9. Timeline, assignments, events all associated with the "Inbiox" ticket

**Result**: Messages from PW1 land in Inbiox's conversation/ticket. The contact sees wrong workspace data. No new PW1 ticket is created.

---

## Root Cause

**File**: `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php`
**Lines**: 156, 196

The `$phoneNumberId` parameter is received but NOT passed to repository lookup methods:

```php
// Line 156 — phoneNumberId available but NOT used
$conversation = $this->conversationRepo->findOpenByContact($contact);

// Line 196 — phoneNumberId available but NOT used
$ticket = $this->ticketRepo->findNotClosedByContact($contact);
```

Repository methods lack `phone_number_id` filtering entirely:
- `ConversationRepository::findOpenByContact(Contact)` — interface at `ConversationRepositoryInterface::findOpenByContact()`
- `TicketRepository::findOpenByContact(Contact)` — interface at `TicketRepositoryInterface::findOpenByContact()`
- `TicketRepository::findNotClosedByContact(Contact)` — interface at `TicketRepositoryInterface::findNotClosedByContact()`

---

## Affected Areas

### Write Paths (set `phone_number_id` correctly)
- `HandleInboundMessageUseCase::executeInternal()` lines 162-164 — sets `phoneNumberId` on new `Conversation` ✓
- `HandleInboundMessageUseCase::executeInternal()` lines 205-207 — sets `phoneNumberId` on new `Ticket` ✓

### Read Paths (missing `phone_number_id` filter) — **BUG**
- `ConversationRepository::findOpenByContact()` — line 18-21, no `phone_number_id` filter
- `TicketRepository::findOpenByContact()` — line 20-23, no `phone_number_id` filter
- `TicketRepository::findNotClosedByContact()` — line 25-28, no `phone_number_id` filter
- Interfaces: `ConversationRepositoryInterface`, `TicketRepositoryInterface` — method signatures lack `phoneNumberId` parameter

### Not Affected
- `HandleStatusUpdateUseCase` — lookup by WAMID, not contact
- `HandleOutboundMessageUseCase` — lookup by ticketId, not contact

---

## Fix Pattern

The fix requires adding an optional `?string $phoneNumberId` parameter to the repository methods and filtering when provided:

```php
// In ConversationRepository
public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Conversation
{
    $criteria = ['contact' => $contact, 'status' => 'open'];
    if ($phoneNumberId !== null) {
        $criteria['phoneNumberId'] = $phoneNumberId;
    }
    return $this->findOneBy($criteria);
}

// In TicketRepository
public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket
{
    $qb = $this->createQueryBuilder('t')
        ->where('t.contact = :contact')
        ->andWhere('t.status IN (:statuses)')
        ->setParameter('contact', $contact)
        ->setParameter('statuses', ['new', 'active', 'waiting']);

    if ($phoneNumberId !== null) {
        $qb->andWhere('t.phoneNumberId = :phoneNumberId')
           ->setParameter('phoneNumberId', $phoneNumberId);
    }

    return $qb->getQuery()->getOneOrNullResult();
}
```

---

## Scope

### In Scope
- Add `?string $phoneNumberId` parameter to `ConversationRepositoryInterface::findOpenByContact()`
- Add `?string $phoneNumberId` parameter to `TicketRepositoryInterface::findOpenByContact()` and `findNotClosedByContact()`
- Update `ConversationRepository::findOpenByContact()` implementation
- Update `TicketRepository::findOpenByContact()` and `findNotClosedByContact()` implementations
- Update `HandleInboundMessageUseCase::executeInternal()` to pass `phoneNumberId` to both repo calls
- Update any other call sites passing `phoneNumberId` to these methods

### Out of Scope
- Adding `phone_number_id` to other queries (separate work item)
- `HandleStatusUpdateUseCase` changes (not affected)
- `HandleOutboundMessageUseCase` changes (not affected)
- Database migrations (column and indexes already exist)

---

## Risks

1. **Backward compatibility**: Existing call sites that don't pass `phoneNumberId` will continue to work (default `null` = no filter), but won't benefit from workspace segregation. All existing call sites need to be audited and updated.

2. **Query complexity**: The `TicketRepository` methods use `findOneBy()` array syntax which doesn't support OR conditions. If complex status filtering is needed, must switch to QueryBuilder (already used in `countActiveForAgent`).

---

## Ready for Proposal

**Yes**. The bug is clearly understood:
- `$phoneNumberId` is available but not passed to repository lookups
- Repository methods and interfaces lack the parameter entirely
- The fix pattern from `resolveActiveTicketIds` shows the correct approach
- Both entities already have the column and indexes
- Only `HandleInboundMessageUseCase` is affected; the other use cases are clean

The fix is surgical: add `?string $phoneNumberId` parameter to 3 interface methods, 3 implementation methods, and 2 call sites in `HandleInboundMessageUseCase`.
