# Spec: Fix Workspace Segregation in Inbound Messages

## Purpose

Inbound message processing MUST scope conversation and ticket lookups to the workspace identified by `phone_number_id`. Currently, lookups ignore `phone_number_id`, causing cross-workspace data leakage.

---

## Requirements

### REQ-1 — Conversation lookup MUST filter by workspace

`ConversationRepository::findOpenByContact()` and its interface MUST accept `?string $phoneNumberId = null`. When non-null, query MUST filter by `phone_number_id`. When null, no filter (backward compatible).

#### Scenario: SC-1 — Contact messages DIFFERENT workspace (main bug)

- GIVEN contact has open conversation in workspace A (`phone_number_id = 'A'`)
- WHEN inbound message arrives for workspace B and `findOpenByContact($contact, 'B')` is called
- THEN returns `null` → NEW conversation created with `phone_number_id = 'B'`

#### Scenario: SC-2 — Contact messages SAME workspace

- GIVEN contact has open conversation in workspace A
- WHEN inbound message arrives for workspace A and `findOpenByContact($contact, 'A')` is called
- THEN returns existing open conversation → message appended (no new conversation)

#### Scenario: SC-3 — New contact, no existing conversation

- GIVEN contact has no conversations in any workspace
- WHEN `findOpenByContact($contact, 'A')` is called
- THEN returns `null` → NEW conversation created with `phone_number_id = 'A'`

---

### REQ-2 — Ticket lookup MUST filter by workspace

`TicketRepository::findOpenByContact()` and `findNotClosedByContact()` (plus interface) MUST accept `?string $phoneNumberId = null`. When non-null, query MUST filter by `phone_number_id`.

#### Scenario: SC-4 — Closed ticket in workspace A, new message to workspace A

- GIVEN contact has only a closed ticket in workspace A
- WHEN `findNotClosedByContact($contact, 'A')` is called
- THEN returns `null` → NEW ticket created for workspace A

#### Scenario: SC-5 — Closed ticket in A, open ticket in B, message to B

- GIVEN contact has closed ticket in A, open ticket in B
- WHEN `findNotClosedByContact($contact, 'B')` is called
- THEN returns workspace B's open ticket (not A's closed one)

---

### REQ-3 — Use case MUST pass phoneNumberId to lookups

`HandleInboundMessageUseCase::executeInternal()` MUST pass the already-available `$phoneNumberId` to both `findOpenByContact()` and `findNotClosedByContact()`.

#### Scenario: End-to-end workspace isolation

- GIVEN contact with open conversation + ticket in workspace A
- WHEN inbound message arrives with `phoneNumberId = 'B'`
- THEN NEW conversation + ticket created with `phone_number_id = 'B'`
- AND workspace A's data remains untouched

---

### REQ-4 — Backward compatibility for null phoneNumberId

When `phoneNumberId` is null, repository methods MUST NOT apply the workspace filter.

#### Scenario: SC-6 — Legacy caller without phoneNumberId

- GIVEN caller invokes `findOpenByContact($contact)` without `phoneNumberId`
- THEN returns first open conversation for contact regardless of workspace (pre-fix behavior preserved)

---

## Edge Cases

| Case | Expected |
|------|----------|
| `phoneNumberId = ""` (empty string) | Filter applies with `= ''`; NULL column values won't match |
| Contact has open conversations in 3 workspaces | Each lookup returns ONLY that workspace's conversation |
| Existing conversation has `phone_number_id = NULL` | Only found when filter is null; filtered lookups skip it |
| Concurrent messages to two workspaces | Scoped by `phoneNumberId`; no race on workspace selection |

## Non-Functional

| Aspect | Requirement |
|--------|-------------|
| Performance | Indexes `idx_conversations_phone_number_id` and `idx_tickets_phone_number_id` already exist — no degradation |
| Data integrity | No data modified, no migration needed (column + indexes from `Version20260613000002`) |
| Backward compat | Default `null` = no filter; existing callers unaffected |

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 4 |
| Scenarios | 6 (SC-1–SC-6) |
| Edge cases | 4 |

## Coverage

- Happy paths: ✅ same/different workspace, new contact
- Edge cases: ✅ empty string, multi-workspace, NULL column, concurrency
- Backward compat: ✅ null parameter preserves legacy behavior

## Next Steps

Ready for design (sdd-design), or tasks (sdd-tasks) if design exists.
